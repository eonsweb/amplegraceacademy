<?php

namespace App\Actions\Assessments;

use App\AssessmentStatus;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\User;
use App\Support\Academic\AssessmentAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveAssessmentScores
{
    /** @param array<int, array{enrollment_id: int|string, score: mixed}> $rows */
    public function handle(User $user, int $assessmentId, array $rows): void
    {
        DB::transaction(function () use ($user, $assessmentId, $rows): void {
            $assessment = Assessment::query()->lockForUpdate()->findOrFail($assessmentId);
            AssessmentAccess::assignments($user, true)->where('academic_year_id', $assessment->academic_year_id)
                ->where('class_level_id', $assessment->class_level_id)->where('subject_id', $assessment->subject_id)->lockForUpdate()->get(['id']);
            Gate::forUser($user)->authorize('recordScores', $assessment);
            if ($assessment->status !== AssessmentStatus::Open) {
                throw ValidationException::withMessages(['rows' => 'This assessment is closed. Reopen it before changing scores.']);
            }
            $validated = Validator::make(['rows' => $rows], [
                'rows' => ['required', 'array', 'min:1', 'max:100'],
                'rows.*' => ['required', 'array:enrollment_id,score'],
                'rows.*.enrollment_id' => ['required', 'integer', 'distinct'],
                'rows.*.score' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.$assessment->maximum_score],
            ])->validate()['rows'];
            $ids = array_column($validated, 'enrollment_id');
            $eligible = $assessment->roster()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');
            if (count($ids) !== $eligible->count()) {
                throw ValidationException::withMessages(['rows' => 'The roster has changed or contains students outside this assessment. Reload before saving.']);
            }
            $existing = AssessmentScore::query()->where('assessment_id', $assessment->id)->whereIn('enrollment_id', $ids)->get()->keyBy('enrollment_id');
            $changes = [];
            foreach ($validated as $row) {
                $score = $row['score'] === null || $row['score'] === '' ? null : number_format((float) $row['score'], 2, '.', '');
                $previous = $existing->get((int) $row['enrollment_id']);
                if (($previous === null && $score === null) || ($previous !== null && $previous->score === $score)) {
                    continue;
                }
                $changes[] = ['assessment_id' => $assessment->id, 'enrollment_id' => (int) $row['enrollment_id'], 'score' => $score, 'entered_by' => $user->id];
            }
            if ($changes !== []) {
                AssessmentScore::query()->upsert($changes, ['assessment_id', 'enrollment_id'], ['score', 'entered_by', 'updated_at']);
            }
        }, 3);
    }
}
