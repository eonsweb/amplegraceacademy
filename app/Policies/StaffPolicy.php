<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\User;
use App\Support\Authorization\Permissions;

class StaffPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::STAFF_VIEW);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Staff $staff): bool
    {
        return $user->can(Permissions::STAFF_VIEW);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can(Permissions::STAFF_CREATE);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Staff $staff): bool
    {
        return $user->can(Permissions::STAFF_UPDATE);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Staff $staff): bool
    {
        return $user->can(Permissions::STAFF_DELETE);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Staff $staff): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Staff $staff): bool
    {
        return false;
    }

    public function manageStatus(User $user, Staff $staff): bool
    {
        return $user->can(Permissions::STAFF_MANAGE_STATUS);
    }

    public function manageUserAccount(User $user, Staff $staff): bool
    {
        return $user->can(Permissions::STAFF_MANAGE_USER_ACCOUNT);
    }
}
