<?php

namespace App\Actions\Fees;

use App\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\Enrollment;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\Term;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Fees\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GenerateInvoices
{
    /** @return Builder<Enrollment> */
    public function eligible(int $yearId, int $termId, int $classId, ?int $studentId = null): Builder
    {
        return Enrollment::query()->where('academic_year_id', $yearId)->where('class_level_id', $classId)->where('status', EnrollmentStatus::Active)
            ->when($studentId !== null, fn (Builder $q) => $q->where('student_id', $studentId))
            ->whereNotIn('student_id', Invoice::query()->select('student_id')->where('term_id', $termId));
    }

    /** @return array{students: int, total: string, fingerprint: string} */
    public function preview(User $user, int $yearId, int $termId, int $classId, ?int $studentId = null): array
    {
        Gate::forUser($user)->authorize(Permissions::INVOICES_GENERATE);
        $this->validateContext($yearId, $termId, $classId);
        $fees = $this->fees($yearId, $termId, $classId)->get();

        return ['students' => $this->eligible($yearId, $termId, $classId, $studentId)->count(), 'total' => Money::decimal($fees->sum(fn (FeeStructure $fee) => Money::minor($fee->amount))),
            'fingerprint' => hash('sha256', $fees->map(fn (FeeStructure $fee) => [$fee->id, $fee->amount, $fee->feeType->name])->toJson())];
    }

    public function handle(User $user, int $yearId, int $termId, int $classId, string $issueDate, ?string $dueDate = null, ?int $studentId = null, ?string $fingerprint = null): int
    {
        Gate::forUser($user)->authorize(Permissions::INVOICES_GENERATE);
        $this->validateContext($yearId, $termId, $classId);
        Validator::make(['issue_date' => $issueDate, 'due_date' => $dueDate], ['issue_date' => ['required', 'date_format:Y-m-d'], 'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date']])->validate();

        return DB::transaction(function () use ($user, $yearId, $termId, $classId, $issueDate, $dueDate, $studentId, $fingerprint): int {
            $term = Term::query()->lockForUpdate()->findOrFail($termId);
            $year = AcademicYear::query()->findOrFail($yearId);
            $class = ClassLevel::query()->findOrFail($classId);
            $fees = $this->fees($yearId, $termId, $classId)->get();
            if ($fees->isEmpty()) {
                throw ValidationException::withMessages(['fees' => 'Configure active fee items before generating invoices.']);
            }
            $currentFingerprint = hash('sha256', $fees->map(fn (FeeStructure $fee) => [$fee->id, $fee->amount, $fee->feeType->name])->toJson());
            if ($fingerprint !== null && ! hash_equals($currentFingerprint, $fingerprint)) {
                throw ValidationException::withMessages(['fees' => 'Fees changed since preview. Preview again before generating.']);
            }
            $created = 0;
            $this->eligible($yearId, $termId, $classId, $studentId)->with('student')->lockForUpdate()->chunkById(200, function ($enrollments) use ($user, $year, $term, $class, $fees, $issueDate, $dueDate, &$created): void {
                $rows = [];
                foreach ($enrollments as $enrollment) {
                    $rows[] = ['student_id' => $enrollment->student_id, 'enrollment_id' => $enrollment->id, 'academic_year_id' => $year->id, 'term_id' => $term->id, 'class_level_id' => $class->id,
                        'invoice_number' => 'INV-'.Str::ulid(), 'student_name' => $enrollment->student->fullName(), 'admission_number' => $enrollment->student->admission_number,
                        'class_name' => $class->name, 'academic_year_name' => $year->name, 'term_name' => $term->name, 'issue_date' => $issueDate, 'due_date' => $dueDate,
                        'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()];
                }
                Invoice::query()->insert($rows);
                $invoices = Invoice::query()->whereIn('invoice_number', array_column($rows, 'invoice_number'))->get(['id']);
                $items = [];
                $audits = [];
                foreach ($invoices as $invoice) {
                    foreach ($fees as $fee) {
                        $items[] = ['invoice_id' => $invoice->id, 'fee_type_id' => $fee->fee_type_id, 'description' => $fee->feeType->name, 'quantity' => 1, 'unit_amount' => $fee->amount, 'amount' => $fee->amount, 'created_at' => now(), 'updated_at' => now()];
                    }
                    $audits[] = ['actor_id' => $user->id, 'action' => 'invoice.created', 'record_type' => 'invoice', 'record_id' => $invoice->id, 'changes' => null, 'created_at' => now()];
                }
                foreach (array_chunk($items, 1000) as $batch) {
                    DB::table('invoice_items')->insert($batch);
                }
                DB::table('financial_audits')->insert($audits);
                $created += $invoices->count();
            });

            return $created;
        }, 3);
    }

    private function validateContext(int $yearId, int $termId, int $classId): void
    {
        Validator::make(['academic_year_id' => $yearId, 'term_id' => $termId, 'class_level_id' => $classId], [
            'academic_year_id' => ['required', 'exists:academic_years,id'],
            'term_id' => ['required', Rule::exists('terms', 'id')->where('academic_year_id', $yearId)],
            'class_level_id' => ['required', 'exists:class_levels,id'],
        ])->validate();
    }

    /** @return Builder<FeeStructure> */
    private function fees(int $yearId, int $termId, int $classId): Builder
    {
        return FeeStructure::query()->where('academic_year_id', $yearId)->where('term_id', $termId)->where('class_level_id', $classId)
            ->where('is_active', true)->whereHas('feeType', fn (Builder $q) => $q->where('is_active', true))->with('feeType:id,name')->orderBy('fee_type_id');
    }
}
