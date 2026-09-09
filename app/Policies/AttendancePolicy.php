<?php

namespace App\Policies;

use App\Models\ClassSubject;
use App\Models\User;
use App\Support\Authorization\Permissions;

class AttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::ATTENDANCE_VIEW);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can(Permissions::ATTENDANCE_RECORD);
    }

    public function update(User $user): bool
    {
        return $this->viewAny($user) && $user->can(Permissions::ATTENDANCE_EDIT);
    }

    public function viewClass(User $user, int $yearId, int $classId): bool
    {
        return $this->viewAny($user) && (! self::requiresAssignment($user)
            || ClassSubject::query()->where('academic_year_id', $yearId)->where('class_level_id', $classId)->where('staff_id', $user->id)->exists());
    }

    public static function requiresAssignment(User $user): bool
    {
        return $user->can(Permissions::ATTENDANCE_RECORD) && ! $user->can(Permissions::ATTENDANCE_EDIT);
    }
}
