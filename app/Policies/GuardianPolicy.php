<?php

namespace App\Policies;

use App\Models\Guardian;
use App\Models\User;
use App\Support\Authorization\Permissions;

class GuardianPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::GUARDIANS_VIEW);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Guardian $guardian): bool
    {
        return $user->can(Permissions::GUARDIANS_VIEW);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can(Permissions::GUARDIANS_CREATE);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Guardian $guardian): bool
    {
        return $user->can(Permissions::GUARDIANS_UPDATE);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Guardian $guardian): bool
    {
        return $user->can(Permissions::GUARDIANS_DELETE);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Guardian $guardian): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Guardian $guardian): bool
    {
        return false;
    }

    public function linkStudent(User $user, Guardian $guardian): bool
    {
        return $user->can(Permissions::GUARDIANS_LINK_STUDENT);
    }

    public function unlinkStudent(User $user, Guardian $guardian): bool
    {
        return $user->can(Permissions::GUARDIANS_UNLINK_STUDENT);
    }
}
