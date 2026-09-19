<?php

namespace App\Actions\Expenses;

use App\ExpenseStatus;
use App\Models\AcademicYear;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Term;
use App\Models\User;
use App\PaymentMethod;
use App\Support\Fees\FeeLedger;
use App\Support\Fees\Money;
use App\Support\Settings\SystemSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveExpense
{
    /** @param array<string, mixed> $input */
    public function handle(User $actor, array $input, bool $record = true, ?int $expenseId = null): Expense
    {
        Gate::forUser($actor)->authorize($expenseId === null ? 'create' : 'update', $expenseId === null ? Expense::class : Expense::query()->findOrFail($expenseId));

        foreach (['payee', 'description', 'reference', 'notes'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }
        foreach (['academic_year_id', 'term_id', 'payment_method', 'payee', 'reference', 'notes'] as $field) {
            if (($input[$field] ?? '') === '') {
                $input[$field] = null;
            }
        }
        $data = Validator::make($input, [
            'expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')],
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')],
            'term_id' => ['nullable', 'integer', Rule::exists('terms', 'id')->where('academic_year_id', $input['academic_year_id'] ?? null)],
            'expense_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now(app(SystemSettings::class)->timezone())->toDateString()],
            'amount' => Money::rules(),
            'description' => ['required', 'string', 'max:500'],
            'payee' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'submission_key' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $data, $record, $expenseId): Expense {
            /** Serialize retries for one recorder before checking the unique submission key. */
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if ($expenseId === null) {
                $existing = Expense::query()->where('submission_key', $data['submission_key'])->first();
                if ($existing !== null) {
                    abort_unless($existing->recorded_by_user_id === $actor->id, 403);

                    return $existing;
                }
            }
            $expense = $expenseId === null ? new Expense : Expense::query()->lockForUpdate()->findOrFail($expenseId);
            if ($expense->exists) {
                Gate::forUser($actor)->authorize('update', $expense);
            }
            $category = ExpenseCategory::query()->whereKey($data['expense_category_id'])->lockForUpdate()->firstOrFail();
            if (! $category->is_active) {
                throw ValidationException::withMessages(['expense_category_id' => 'Select an active expense category.']);
            }
            $before = $expense->exists ? $expense->attributesToArray() : null;
            $values = $data;
            unset($values['submission_key']);
            $values['amount'] = Money::decimal(Money::minor($data['amount']));
            $values['status'] = $record ? ExpenseStatus::Recorded : ExpenseStatus::Draft;
            $values['recorded_at'] = $record ? now() : null;
            $values['category_name'] = $category->name;
            $values['academic_year_name'] = $data['academic_year_id'] === null ? null : AcademicYear::query()->whereKey($data['academic_year_id'])->firstOrFail()->name;
            $values['term_name'] = $data['term_id'] === null ? null : Term::query()->whereKey($data['term_id'])->firstOrFail()->name;
            if (! $expense->exists) {
                $values['submission_key'] = $data['submission_key'];
                $values['recorded_by_user_id'] = $actor->id;
                $values['recorded_by_name'] = $actor->name;
            }
            $expense->forceFill($values)->save();
            FeeLedger::audit($actor, $record ? 'expense.recorded' : 'expense.draft_saved', 'expense', $expense->id,
                ['before' => $before, 'after' => $expense->attributesToArray()]);

            return $expense;
        }, 3);
    }

    public function deleteDraft(User $actor, int $expenseId): void
    {
        DB::transaction(function () use ($actor, $expenseId): void {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expenseId);
            Gate::forUser($actor)->authorize('delete', $expense);
            FeeLedger::audit($actor, 'expense.draft_deleted', 'expense', $expense->id, ['before' => $expense->attributesToArray()]);
            $expense->delete();
        }, 3);
    }
}
