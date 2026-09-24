<?php

namespace App\Support\Reports;

use App\Models\User;
use App\Policies\AttendancePolicy;
use App\Support\Academic\AssessmentAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class EnrollmentReports
{
    /** @param array<string, string> $filters */
    public static function roster(User $user, array $filters, bool $academic = false): Builder
    {
        $query = DB::table('enrollments')
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->join('academic_years', 'academic_years.id', '=', 'enrollments.academic_year_id')
            ->join('class_levels', 'class_levels.id', '=', 'enrollments.class_level_id');
        ReportFilters::columns($query, 'enrollments', $filters, ['academic_year_id', 'class_level_id']);
        ReportFilters::student($query, 'enrollments.student_id', $filters);
        if (($academic && ! AssessmentAccess::allSubjects($user)) || (! $academic && AttendancePolicy::requiresAssignment($user))) {
            $query->whereExists(DB::table('class_subjects')->selectRaw('1')
                ->whereColumn('class_subjects.academic_year_id', 'enrollments.academic_year_id')
                ->whereColumn('class_subjects.class_level_id', 'enrollments.class_level_id')->where('staff_id', $user->id));
        }

        return $query;
    }

    /** @return list<string> */
    public static function identity(): array
    {
        return ['students.admission_number', 'academic_years.name as academic_year_name', 'class_levels.name as class_name'];
    }

    public static function name(Builder $query): Builder
    {
        return $query->selectRaw("CONCAT_WS(' ', students.first_name, NULLIF(students.middle_name, ''), students.last_name) AS student_name");
    }

    /** @param array<string, string> $filters */
    public static function query(User $user, string $report, array $filters): Builder
    {
        $query = self::roster($user, $filters);
        if ($report === 'class-enrollment') {
            return $query->select('enrollments.academic_year_id', 'enrollments.class_level_id', 'academic_years.name as academic_year_name', 'class_levels.name as class_name')
                ->selectRaw("COUNT(DISTINCT enrollments.student_id) AS students, COUNT(*) AS records,
                    SUM(CASE WHEN enrollments.status = 'active' THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN enrollments.status <> 'active' THEN 1 ELSE 0 END) AS other,
                    COUNT(DISTINCT CASE WHEN students.gender = 'male' THEN students.id END) AS male,
                    COUNT(DISTINCT CASE WHEN students.gender = 'female' THEN students.id END) AS female")
                ->groupBy('enrollments.academic_year_id', 'enrollments.class_level_id', 'academic_years.name', 'class_levels.name', 'class_levels.level_order')
                ->orderByDesc('academic_years.name')->orderBy('class_levels.level_order')->orderBy('enrollments.class_level_id');
        }
        ReportFilters::columns($query, 'enrollments', $filters, ['status']);

        return self::name($query->select('enrollments.id', 'enrollments.student_id', 'enrollments.status', ...self::identity()))
            ->orderByDesc('academic_years.name')->orderBy('class_levels.level_order')->orderBy('enrollments.id');
    }

    /** @param array<string, string> $filters
     * @return array<string, int|string|null>
     */
    public static function summary(User $user, array $filters): array
    {
        $query = self::roster($user, $filters);
        ReportFilters::columns($query, 'enrollments', $filters, ['status']);
        $row = $query->selectRaw("COUNT(DISTINCT students.id) AS students, COUNT(*) AS records,
            COUNT(CASE WHEN enrollments.status = 'active' THEN 1 END) AS active,
            COUNT(CASE WHEN enrollments.status <> 'active' THEN 1 END) AS other")->first();

        return ['Students' => $row->students, 'Enrollments' => $row->records, 'Active enrollments' => $row->active, 'Other statuses' => $row->other];
    }
}
