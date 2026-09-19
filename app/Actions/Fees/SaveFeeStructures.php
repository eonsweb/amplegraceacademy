<?php

namespace App\Actions\Fees;

use App\Models\ClassLevel;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\Term;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Fees\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveFeeStructures
{
    /** @param list<array{class_level_id: int|string, fee_type_id: int|string, amount: string, is_active: bool}> $rows */
    public function handle(User $user, int $yearId, int $termId, array $rows): void
    {
        Gate::forUser($user)->authorize(Permissions::FEES_MANAGE);
        Validator::make(['year' => $yearId, 'term' => $termId, 'rows' => $rows], [
            'year' => ['required', 'exists:academic_years,id'],
            'term' => ['required', Rule::exists('terms', 'id')->where('academic_year_id', $yearId)],
            'rows' => ['required', 'array', 'min:1', 'max:1000'],
            'rows.*.class_level_id' => ['required', 'integer'],
            'rows.*.fee_type_id' => ['required', 'integer'],
            'rows.*.amount' => Money::rules(),
            'rows.*.is_active' => ['required', 'boolean'],
        ])->validate();
        DB::transaction(function () use ($user, $yearId, $termId, $rows): void {
            Term::query()->lockForUpdate()->findOrFail($termId);
            $classIds = ClassLevel::query()->whereIn('id', array_column($rows, 'class_level_id'))->pluck('id')->all();
            $feeIds = FeeType::query()->whereIn('id', array_column($rows, 'fee_type_id'))->pluck('id')->all();
            $seen = [];
            $values = [];
            foreach ($rows as $index => $row) {
                $key = $row['class_level_id'].':'.$row['fee_type_id'];
                if (isset($seen[$key])) {
                    throw ValidationException::withMessages(["rows.$index.amount" => 'Duplicate class and fee type.']);
                }
                if (! in_array((int) $row['class_level_id'], $classIds, true) || ! in_array((int) $row['fee_type_id'], $feeIds, true)) {
                    throw ValidationException::withMessages(["rows.$index.amount" => 'Select an existing class and fee type.']);
                }
                $seen[$key] = true;
                $values[] = ['academic_year_id' => $yearId, 'term_id' => $termId, 'class_level_id' => (int) $row['class_level_id'], 'fee_type_id' => (int) $row['fee_type_id'], 'amount' => Money::decimal(Money::minor($row['amount'])), 'is_active' => $row['is_active'], 'updated_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()];
            }
            $before = FeeStructure::query()->where('term_id', $termId)->whereIn('class_level_id', $classIds)->get()->keyBy(fn (FeeStructure $row) => $row->class_level_id.':'.$row->fee_type_id);
            FeeStructure::query()->upsert($values, ['academic_year_id', 'term_id', 'class_level_id', 'fee_type_id'], ['amount', 'is_active', 'updated_by_user_id', 'updated_at']);
            $records = FeeStructure::query()->where('term_id', $termId)->whereIn('class_level_id', $classIds)->get();
            $audits = [];
            foreach ($records as $record) {
                $key = $record->class_level_id.':'.$record->fee_type_id;
                if (! isset($seen[$key])) {
                    continue;
                }
                $old = $before->get($key);
                $audits[] = ['actor_id' => $user->id, 'action' => 'structure.saved', 'record_type' => 'fee_structure', 'record_id' => $record->id, 'changes' => json_encode(['before' => $old?->only(['amount', 'is_active']), 'after' => $record->only(['amount', 'is_active'])], JSON_THROW_ON_ERROR), 'created_at' => now()];
            }
            DB::table('financial_audits')->insert($audits);
        }, 3);
    }
}
