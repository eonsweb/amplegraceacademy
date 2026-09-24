<?php

namespace App\Support\Reports;

use App\AttendanceStatus;
use App\EnrollmentStatus;
use App\ExpenseStatus;
use App\InvoiceStatus;
use App\Models\User;
use App\PaymentMethod;
use App\Policies\AttendancePolicy;
use App\Support\Academic\AssessmentAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ReportFilters
{
    /** @return array<string, string> */
    public static function labels(): array
    {
        return ['academic_year_id' => 'Academic year', 'term_id' => 'Term', 'class_level_id' => 'Class',
            'subject_id' => 'Subject', 'assessment_id' => 'Assessment', 'student' => 'Student admission number (exact)',
            'date' => 'Date', 'date_from' => 'From date', 'date_to' => 'To date', 'status' => 'Status',
            'payment_method' => 'Payment method', 'fee_type_id' => 'Fee type', 'expense_category_id' => 'Expense category',
            'recorded_by_user_id' => 'Recorded by'];
    }

    /** @param array<string, mixed> $filters
     * @return array<string, array<int|string, string>>
     */
    public static function options(User $user, string $report, array $filters, string $assessmentSearch = ''): array
    {
        $filters = array_map(fn ($value): string => is_scalar($value) ? (string) $value : '', $filters);
        foreach (['academic_year_id', 'term_id', 'class_level_id', 'subject_id'] as $key) {
            if (! ctype_digit($filters[$key] ?? '')) {
                $filters[$key] = '';
            }
        }
        $options = [];
        $requiresAssignment = match (true) {
            in_array($report, ['enrollment', 'class-enrollment'], true), ReportCatalog::get($report)['group'] === 'Attendance' => AttendancePolicy::requiresAssignment($user),
            ReportCatalog::get($report)['group'] === 'Academic' => ! AssessmentAccess::allSubjects($user),
            default => false,
        };
        foreach (ReportCatalog::get($report)['filters'] as $field) {
            $query = match ($field) {
                'academic_year_id' => DB::table('academic_years')->orderByDesc('name'),
                'term_id' => DB::table('terms')->where('academic_year_id', $filters['academic_year_id'] ?: 0)->orderBy('term_order'),
                'class_level_id' => DB::table('class_levels')->orderBy('level_order'),
                'subject_id' => DB::table('subjects')->orderBy('name'),
                'expense_category_id' => DB::table('expense_categories')->orderBy('name'),
                'fee_type_id' => DB::table('fee_types')->orderBy('name'),
                'recorded_by_user_id' => DB::table('users')->whereIn('id', DB::table('expenses')->select('recorded_by_user_id'))->orderBy('name'),
                default => null,
            };
            if ($query !== null) {
                if (in_array($field, ['class_level_id', 'subject_id'], true)
                    && $requiresAssignment) {
                    $column = $field === 'class_level_id' ? 'class_level_id' : 'subject_id';
                    $assignments = DB::table('class_subjects')->where('staff_id', $user->id)->select($column);
                    if ($filters['academic_year_id'] !== '') {
                        $assignments->where('academic_year_id', $filters['academic_year_id']);
                    }
                    $query->whereIn('id', $assignments);
                }
                $options[$field] = $query->pluck('name', 'id')->all();
            } elseif ($field === 'assessment_id') {
                $query = AssessmentAccess::assessments($user);
                self::columns($query->getQuery(), 'assessments', $filters, ['academic_year_id', 'term_id', 'class_level_id', 'subject_id']);
                $selected = ctype_digit($filters['assessment_id'] ?? '')
                    ? (clone $query)->find($filters['assessment_id'], ['id', 'name']) : null;
                $search = mb_substr(trim($assessmentSearch), 0, 100);
                if ($search !== '') {
                    if (ctype_digit($search)) {
                        $query->whereKey($search);
                    } else {
                        $query->where('name', 'like', '%'.$search.'%');
                    }
                }
                $assessments = $query->orderByDesc('id')->limit(100)->get(['id', 'name']);
                if ($selected !== null && ! $assessments->contains('id', $selected->id)) {
                    $assessments->prepend($selected);
                }
                $options[$field] = $assessments
                    ->mapWithKeys(fn ($assessment): array => [$assessment->id => '#'.$assessment->id.' '.$assessment->name])->all();
            } elseif ($field === 'payment_method') {
                $options[$field] = collect(PaymentMethod::cases())->mapWithKeys(fn ($method): array => [$method->value => $method->label()])->all();
            } elseif ($field === 'status') {
                $options[$field] = self::statuses($report);
            }
        }

        return $options;
    }

    /** @param array<string, string> $filters
     * @return array<string, string>
     */
    public static function describe(User $user, string $report, array $filters): array
    {
        $options = self::options($user, $report, $filters);
        $description = [];
        foreach ($filters as $field => $value) {
            if ($value !== '') {
                $description[self::labels()[$field]] = $options[$field][$value] ?? $value;
            }
        }

        return $description;
    }

    /** @return array<string, string> */
    public static function statuses(string $report): array
    {
        $cases = match ($report) {
            'enrollment' => EnrollmentStatus::cases(),
            'attendance-daily', 'attendance-students' => AttendanceStatus::cases(),
            'expenses' => ExpenseStatus::cases(),
            'fees' => InvoiceStatus::cases(),
            default => [],
        };
        if ($report === 'payments') {
            return ['valid' => 'Valid', 'void' => 'Voided'];
        }
        if ($report === 'outstanding') {
            return ['outstanding' => 'Outstanding only', 'paid' => 'Fully paid'];
        }

        return collect($cases)->mapWithKeys(fn ($case): array => [$case->value => $case->label()])->all();
    }

    /** @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function validate(User $user, string $report, array $input): array
    {
        $allowed = ReportCatalog::get($report)['filters'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw ValidationException::withMessages(['filters' => 'This report received an unsupported filter.']);
        }
        $data = array_fill_keys($allowed, '');
        foreach ($allowed as $field) {
            $data[$field] = $input[$field] ?? '';
        }
        $tables = ['academic_year_id' => 'academic_years', 'term_id' => 'terms', 'class_level_id' => 'class_levels',
            'subject_id' => 'subjects', 'assessment_id' => 'assessments', 'fee_type_id' => 'fee_types',
            'expense_category_id' => 'expense_categories', 'recorded_by_user_id' => 'users'];
        $rules = [];
        foreach ($allowed as $field) {
            $rules[$field] = ['nullable', 'string', 'max:100'];
            if (isset($tables[$field])) {
                $exists = Rule::exists($tables[$field], 'id');
                if ($field === 'term_id' && ($data['academic_year_id'] ?? '') !== '') {
                    $exists->where('academic_year_id', is_string($data['academic_year_id']) || is_int($data['academic_year_id']) ? $data['academic_year_id'] : 0);
                }
                $rules[$field] = ['bail', 'nullable', 'integer', $exists];
            }
            if (in_array($field, ['date', 'date_from', 'date_to'], true)) {
                $rules[$field] = ['nullable', 'date_format:Y-m-d'];
            }
        }
        if (in_array('status', $allowed, true)) {
            $rules['status'] = ['bail', 'nullable', 'string', Rule::in(array_keys(self::statuses($report)))];
        }
        if (in_array('payment_method', $allowed, true)) {
            $rules['payment_method'] = ['nullable', Rule::enum(PaymentMethod::class)];
        }
        if (in_array('student', $allowed, true)) {
            $rules['student'] = ['bail', 'nullable', 'string', 'max:100', Rule::exists('students', 'admission_number')];
        }
        $required = match ($report) {
            'class-results' => ['academic_year_id', 'term_id', 'class_level_id'],
            'assessment-results' => ['assessment_id'],
            'attendance-students' => ['student'],
            'attendance-daily' => ['date'],
            default => [],
        };
        foreach ($required as $field) {
            array_unshift($rules[$field], 'required');
        }
        $values = Validator::make($data, $rules)->validate();
        if (($values['date_from'] ?? '') !== '' && isset($rules['date_to'])) {
            Validator::make($values, ['date_to' => ['nullable', 'after_or_equal:date_from']])->validate();
        }
        $filters = array_map(fn ($value): string => (string) ($value ?? ''), $values);
        if (($filters['assessment_id'] ?? '') !== '') {
            $assessment = AssessmentAccess::assessments($user)->find($filters['assessment_id']);
            if ($assessment === null) {
                throw ValidationException::withMessages(['assessment_id' => 'Select an assessment you are authorized to view.']);
            }
            foreach (['academic_year_id', 'term_id', 'class_level_id', 'subject_id'] as $field) {
                if (($filters[$field] ?? '') !== '' && (string) $assessment->$field !== $filters[$field]) {
                    throw ValidationException::withMessages(['assessment_id' => 'The assessment must match the selected academic filters.']);
                }
            }
        }

        return $filters;
    }

    /** @param array<string, string> $filters */
    public static function dates(Builder $query, string $column, array $filters): void
    {
        if (($filters['date'] ?? '') !== '') {
            $query->where($column, $filters['date']);
        }
        if (($filters['date_from'] ?? '') !== '') {
            $query->where($column, '>=', $filters['date_from']);
        }
        if (($filters['date_to'] ?? '') !== '') {
            $query->where($column, '<=', $filters['date_to']);
        }
    }

    /** @param array<string, string> $filters
     * @param  list<string>  $columns
     */
    public static function columns(Builder $query, string $table, array $filters, array $columns): void
    {
        foreach ($columns as $column) {
            if (($filters[$column] ?? '') !== '') {
                $query->where($table.'.'.$column, $filters[$column]);
            }
        }
    }

    /** @param array<string, string> $filters */
    public static function student(Builder $query, string $column, array $filters): void
    {
        if (($filters['student'] ?? '') !== '') {
            $query->whereIn($column, DB::table('students')->select('id')->where('admission_number', $filters['student']));
        }
    }
}
