<?php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\User;
use App\Support\Academic\AssessmentAccess;
use App\Support\Authorization\Permissions;

class AssessmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::ASSESSMENTS_VIEW);
    }

    public function view(User $user, Assessment $assessment): bool
    {
        return $this->viewAny($user) && AssessmentAccess::context($user, $assessment->academic_year_id, $assessment->class_level_id, $assessment->subject_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can(Permissions::ASSESSMENTS_CREATE);
    }

    public function update(User $user, Assessment $assessment): bool
    {
        return $this->viewAny($user) && $user->can(Permissions::ASSESSMENTS_UPDATE) && $this->assigned($user, $assessment);
    }

    public function delete(User $user, Assessment $assessment): bool
    {
        return $this->viewAny($user) && $user->can(Permissions::ASSESSMENTS_DELETE) && $this->assigned($user, $assessment);
    }

    public function recordScores(User $user, Assessment $assessment): bool
    {
        return $this->viewAny($user) && $user->can(Permissions::ASSESSMENTS_RECORD_SCORES) && $this->assigned($user, $assessment);
    }

    private function assigned(User $user, Assessment $assessment): bool
    {
        return AssessmentAccess::context($user, $assessment->academic_year_id, $assessment->class_level_id, $assessment->subject_id, true);
    }
}
