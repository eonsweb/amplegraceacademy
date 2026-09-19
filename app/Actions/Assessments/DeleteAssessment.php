<?php

namespace App\Actions\Assessments;

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteAssessment
{
    public function handle(User $user, int $assessmentId): void
    {
        DB::transaction(function () use ($user, $assessmentId): void {
            $assessment = Assessment::query()->lockForUpdate()->findOrFail($assessmentId);
            Gate::forUser($user)->authorize('delete', $assessment);
            if ($assessment->scores()->exists()) {
                throw ValidationException::withMessages(['assessment' => 'This assessment has saved scores and cannot be deleted. Close it to prevent score entry.']);
            }
            $assessment->delete();
        }, 3);
    }
}
