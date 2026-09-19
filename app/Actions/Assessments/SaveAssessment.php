<?php

namespace App\Actions\Assessments;

use App\AssessmentStatus;
use App\AssessmentType;
use App\Models\Assessment;
use App\Models\User;
use App\Support\Academic\AssessmentAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveAssessment
{
    /** @param array<string, mixed> $data */
    public function handle(User $user, array $data, ?int $assessmentId = null): Assessment
    {
        return DB::transaction(function () use ($user, $data, $assessmentId): Assessment {
            $assessment = $assessmentId === null ? new Assessment : Assessment::query()->lockForUpdate()->findOrFail($assessmentId);
            $gate = Gate::forUser($user);
            $gate->authorize($assessment->exists ? 'update' : 'create', $assessment->exists ? $assessment : Assessment::class);
            $validated = Validator::make($data, [
                'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
                'term_id' => ['required', 'integer', Rule::exists('terms', 'id')->where('academic_year_id', filter_var($data['academic_year_id'] ?? null, FILTER_VALIDATE_INT) ?: 0)],
                'class_level_id' => ['required', 'integer', 'exists:class_levels,id'],
                'subject_id' => ['required', 'integer', 'exists:subjects,id'],
                'name' => ['required', 'string', 'max:150'],
                'type' => ['required', Rule::enum(AssessmentType::class)],
                'maximum_score' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999.99'],
                'assessment_date' => ['nullable', 'date_format:Y-m-d'],
                'status' => ['required', Rule::enum(AssessmentStatus::class)],
            ])->validate();
            $assignment = AssessmentAccess::assignments($user, true)
                ->where('academic_year_id', $validated['academic_year_id'])->where('class_level_id', $validated['class_level_id'])
                ->where('subject_id', $validated['subject_id'])->lockForUpdate()->first();
            if ($assignment === null) {
                throw ValidationException::withMessages(['subject_id' => 'Select a subject assigned to you for this class and academic year.']);
            }
            $assessment->fill($validated)->save();

            return $assessment;
        }, 3);
    }
}
