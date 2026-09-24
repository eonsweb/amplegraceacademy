<?php

namespace App\Support\Reports;

use App\Models\Enrollment;
use App\Models\User;
use App\Support\Academic\AssessmentAccess;
use App\Support\Academic\ResultCalculator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AcademicReports
{
    /** @param array<string, string> $filters */
    public static function query(User $user, string $report, array $filters): Builder
    {
        if ($report === 'class-results') {
            return EnrollmentReports::name(EnrollmentReports::roster($user, $filters, true)
                ->select('enrollments.id', ...EnrollmentReports::identity()))->orderBy('enrollments.id');
        }
        if ($report === 'assessment-results') {
            $assessment = AssessmentAccess::assessments($user)->findOrFail($filters['assessment_id']);
            $context = [...$filters, 'academic_year_id' => (string) $assessment->academic_year_id, 'class_level_id' => (string) $assessment->class_level_id];
            $query = EnrollmentReports::roster($user, $context, true)
                ->whereIn('enrollments.id', $assessment->roster()->select('enrollments.id'))
                ->leftJoin('assessment_scores', fn ($join) => $join->on('assessment_scores.enrollment_id', '=', 'enrollments.id')->where('assessment_scores.assessment_id', $assessment->id))
                ->join('assessments', fn ($join) => $join->where('assessments.id', $assessment->id))
                ->join('subjects', 'subjects.id', '=', 'assessments.subject_id');

            return EnrollmentReports::name($query->select(['enrollments.id', ...EnrollmentReports::identity(),
                'assessments.name as assessment_name', 'subjects.name as subject_name', 'assessment_scores.score', 'assessments.maximum_score as maximum']))
                ->orderBy('enrollments.id');
        }
        $query = DB::table('assessment_scores')->join('assessments', 'assessments.id', '=', 'assessment_scores.assessment_id')
            ->join('subjects', 'subjects.id', '=', 'assessments.subject_id')
            ->whereIn('assessments.id', AssessmentAccess::assessments($user)->select('assessments.id'))->whereNotNull('assessment_scores.score');
        ReportFilters::columns($query, 'assessments', $filters, ['academic_year_id', 'term_id', 'class_level_id', 'subject_id']);
        if (($filters['assessment_id'] ?? '') !== '') {
            $query->where('assessments.id', $filters['assessment_id']);
        }

        return $query->join('enrollments', 'enrollments.id', '=', 'assessment_scores.enrollment_id')
            ->select('subjects.id', 'subjects.name as subject_name')
            ->selectRaw('COUNT(DISTINCT enrollments.student_id) AS students, COUNT(*) AS records, ROUND(AVG(assessment_scores.score), 2) AS average_score,
                MAX(assessment_scores.score) AS highest, MIN(assessment_scores.score) AS lowest,
                ROUND(AVG(100.0 * assessment_scores.score / NULLIF(assessments.maximum_score, 0)), 2) AS percentage')
            ->groupBy('subjects.id', 'subjects.name')->orderBy('subjects.name')->orderBy('subjects.id');
    }

    /** @param array<string, string> $filters
     * @param  Collection<int, \stdClass>  $rows
     * @return Collection<int, \stdClass>
     */
    public static function decorate(User $user, string $report, array $filters, Collection $rows): Collection
    {
        if ($report === 'assessment-results') {
            return $rows->map(function (\stdClass $row): \stdClass {
                $row->percentage = $row->score === null ? null : ResultCalculator::percentage((float) $row->score, (float) $row->maximum);
                $row->status = $row->score === null ? 'Incomplete' : 'Complete';

                return $row;
            });
        }
        if ($report !== 'class-results' || $rows->isEmpty()) {
            return $rows;
        }
        $enrollments = Enrollment::query()->whereIn('id', $rows->pluck('id'))->get();
        $results = app(ResultCalculator::class)->forClass($user, (int) $filters['academic_year_id'], (int) $filters['term_id'],
            (int) $filters['class_level_id'], $enrollments, ($filters['subject_id'] ?? '') !== '' ? (int) $filters['subject_id'] : null);

        return $rows->map(function (\stdClass $row) use ($results): \stdClass {
            $result = $results[$row->id];
            $row->score = $result['earned'];
            $row->maximum = $result['maximum'];
            $row->percentage = $result['percentage'];
            $row->status = $result['complete'] ? 'Complete' : 'Incomplete';
            $row->subjects = collect($result['subjects'])->map(fn (array $subject): string => $subject['name'].': '.$subject['earned'].'/'.$subject['maximum'].($subject['complete'] ? '' : ' (incomplete)'))->implode('; ');

            return $row;
        });
    }
}
