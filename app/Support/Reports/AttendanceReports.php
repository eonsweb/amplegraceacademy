<?php

namespace App\Support\Reports;

use App\Models\User;
use Illuminate\Database\Query\Builder;

final class AttendanceReports
{
    /** @param array<string, string> $filters */
    private static function base(User $user, array $filters): Builder
    {
        $query = EnrollmentReports::roster($user, $filters)
            ->join('attendances', 'attendances.enrollment_id', '=', 'enrollments.id')
            ->join('terms', 'terms.id', '=', 'attendances.term_id');
        ReportFilters::columns($query, 'attendances', $filters, ['term_id', 'status']);
        ReportFilters::dates($query, 'attendances.attendance_date', $filters);

        return $query;
    }

    private static function totals(Builder $query): Builder
    {
        return $query->selectRaw("COUNT(*) AS records,
            COUNT(CASE WHEN attendances.status = 'present' THEN 1 END) AS present,
            COUNT(CASE WHEN attendances.status = 'absent' THEN 1 END) AS absent,
            COUNT(CASE WHEN attendances.status = 'late' THEN 1 END) AS late,
            COUNT(CASE WHEN attendances.status = 'excused' THEN 1 END) AS excused,
            ROUND(100.0 * COUNT(CASE WHEN attendances.status IN ('present', 'late') THEN 1 END) / NULLIF(COUNT(*), 0), 2) AS percentage");
    }

    /** @param array<string, string> $filters */
    public static function query(User $user, string $report, array $filters): Builder
    {
        $query = self::base($user, $filters);
        if ($report === 'attendance-classes') {
            return self::totals($query->select('academic_years.name as academic_year_name', 'class_levels.name as class_name'))
                ->groupBy('enrollments.academic_year_id', 'enrollments.class_level_id', 'academic_years.name', 'class_levels.name', 'class_levels.level_order')
                ->orderByDesc('academic_years.name')->orderBy('class_levels.level_order')->orderBy('enrollments.class_level_id');
        }

        return EnrollmentReports::name($query->select('attendances.id', 'attendances.attendance_date as date',
            'attendances.status', 'terms.name as term_name', ...EnrollmentReports::identity()))
            ->orderByDesc('attendances.attendance_date')->orderBy('attendances.id');
    }

    /** @param array<string, string> $filters
     * @return array<string, int|string|null>
     */
    public static function summary(User $user, array $filters): array
    {
        $row = self::totals(self::base($user, $filters))->first();

        return ['Records' => $row->records, 'Present' => $row->present, 'Absent' => $row->absent,
            'Late' => $row->late, 'Excused' => $row->excused, 'Attendance %' => $row->percentage];
    }
}
