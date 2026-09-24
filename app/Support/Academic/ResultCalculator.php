<?php

namespace App\Support\Academic;

use App\Models\AssessmentScore;
use App\Models\Enrollment;
use App\Models\Term;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ResultCalculator
{
    public static function percentage(float $earned, float $maximum): ?float
    {
        return $maximum > 0 ? round($earned / $maximum * 100, 2) : null;
    }

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     * @return array<int, array{subjects: array<int, array<string, mixed>>, earned: float, maximum: float, percentage: ?float, complete: bool}>
     */
    public function forClass(User $user, int $yearId, int $termId, int $classId, Collection $enrollments, ?int $subjectId = null): array
    {
        Gate::forUser($user)->authorize(Permissions::RESULTS_VIEW);
        if (! Term::query()->whereKey($termId)->where('academic_year_id', $yearId)->exists()) {
            throw ValidationException::withMessages(['termId' => 'Select a term in the selected academic year.']);
        }
        if ($enrollments->contains(fn (Enrollment $enrollment): bool => $enrollment->academic_year_id !== $yearId || $enrollment->class_level_id !== $classId)) {
            throw ValidationException::withMessages(['classLevelId' => 'The result roster does not match the selected class and year.']);
        }
        $assessments = AssessmentAccess::assessments($user)->where('academic_year_id', $yearId)->where('term_id', $termId)
            ->where('class_level_id', $classId)->when($subjectId !== null, fn ($query) => $query->where('subject_id', $subjectId))
            ->with('subject:id,name')->orderBy('id')->get();
        $subjects = AssessmentAccess::assignments($user)->where('academic_year_id', $yearId)->where('class_level_id', $classId)
            ->when($subjectId !== null, fn ($query) => $query->where('subject_id', $subjectId))->with('subject:id,name')->get()
            ->mapWithKeys(fn ($assignment): array => [$assignment->subject_id => $assignment->subject->name]);
        foreach ($assessments as $assessment) {
            $subjects->put($assessment->subject_id, $assessment->subject->name);
        }
        $scores = AssessmentScore::query()->whereIn('assessment_id', $assessments->modelKeys())
            ->whereIn('enrollment_id', $enrollments->pluck('id'))->get()->keyBy(fn (AssessmentScore $score): string => $score->enrollment_id.':'.$score->assessment_id);
        $bySubject = $assessments->groupBy('subject_id');
        $results = [];
        foreach ($enrollments as $enrollment) {
            $subjectResults = [];
            $totalEarned = 0;
            $totalMaximum = 0;
            $complete = $subjects->isNotEmpty();
            foreach ($subjects->sort() as $id => $name) {
                $earned = 0;
                $maximum = 0;
                $missing = 0;
                $breakdown = [];
                foreach ($bySubject->get($id, collect()) as $assessment) {
                    $score = $scores->get($enrollment->id.':'.$assessment->id);
                    if ($score === null && $assessment->assessment_date !== null && $enrollment->enrollment_date->gt($assessment->assessment_date)) {
                        continue;
                    }
                    $maximum += (int) round((float) $assessment->maximum_score * 100);
                    if ($score?->score === null) {
                        $missing++;
                    } else {
                        $earned += (int) round((float) $score->score * 100);
                    }
                    $breakdown[] = ['name' => $assessment->name, 'score' => $score?->score, 'maximum' => $assessment->maximum_score];
                }
                $subjectComplete = $breakdown !== [] && $missing === 0;
                $complete = $complete && $subjectComplete;
                $subjectResults[$id] = ['name' => $name, 'earned' => $earned / 100, 'maximum' => $maximum / 100,
                    'missing' => $missing, 'complete' => $subjectComplete, 'percentage' => $subjectComplete ? self::percentage($earned, $maximum) : null,
                    'assessments' => $breakdown];
                $totalEarned += $earned;
                $totalMaximum += $maximum;
            }
            $results[$enrollment->id] = ['subjects' => $subjectResults, 'earned' => $totalEarned / 100, 'maximum' => $totalMaximum / 100,
                'percentage' => $complete ? self::percentage($totalEarned, $totalMaximum) : null, 'complete' => $complete];
        }

        return $results;
    }
}
