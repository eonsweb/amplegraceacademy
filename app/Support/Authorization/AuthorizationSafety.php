<?php

namespace App\Support\Authorization;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

final class AuthorizationSafety
{
    public function ensureRoleMayBeDeleted(Role $role): void
    {
        if (in_array($role->name, Roles::initial(), true)) {
            throw ValidationException::withMessages(['role' => 'Initial school roles cannot be deleted.']);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages(['role' => 'Reassign this role’s users before deleting it.']);
        }
    }

    public function ensureRoleNameMayChange(Role $role, string $newName): void
    {
        if ($role->name !== $newName && in_array($role->name, Roles::initial(), true)) {
            throw ValidationException::withMessages(['name' => 'Initial school roles cannot be renamed.']);
        }
    }

    /**
     * @param  list<string>  $permissionNames
     */
    public function ensurePermissionsMayBeGranted(User $actor, array $permissionNames): void
    {
        $unauthorized = collect($permissionNames)
            ->first(fn (string $permission): bool => ! $actor->can($permission));

        if ($unauthorized !== null) {
            throw ValidationException::withMessages([
                'permissionNames' => 'You cannot grant a permission you do not hold.',
            ]);
        }
    }

    /**
     * @param  list<string>  $roleNames
     * @param  list<string>  $directPermissionNames
     */
    public function ensureRolesAndPermissionsMayBeGranted(User $actor, array $roleNames, array $directPermissionNames): void
    {
        $rolePermissionNames = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $roleNames)
            ->with('permissions:id,name')
            ->get()
            ->flatMap(fn (Role $role): Collection => $role->permissions->pluck('name'))
            ->map(fn (mixed $permission): string => (string) $permission);

        $this->ensurePermissionsMayBeGranted(
            $actor,
            array_values($rolePermissionNames->merge($directPermissionNames)->unique()->all()),
        );
    }

    public function ensureUserMayBeManaged(User $actor, User $subject): void
    {
        if ($actor->is($subject)) {
            throw ValidationException::withMessages([
                'authorization' => 'You cannot change your own roles or direct permissions.',
            ]);
        }
    }

    /**
     * @param  list<string>  $roleNames
     * @param  list<string>  $directPermissionNames
     */
    public function ensureAdministrativeAccessRemains(User $subject, array $roleNames, array $directPermissionNames): void
    {
        if ($this->authorizationManagerExistsExcluding([$subject->getKey()])) {
            return;
        }

        $proposedPermissions = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $roleNames)
            ->with('permissions:id,name')
            ->get()
            ->flatMap(fn (Role $role): Collection => $role->permissions->pluck('name'))
            ->merge($directPermissionNames)
            ->unique();

        if (collect(Permissions::critical())->every(fn (string $permission): bool => $proposedPermissions->contains($permission))) {
            return;
        }

        throw ValidationException::withMessages([
            'authorization' => 'At least one user must retain complete authorization management access.',
        ]);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    public function ensureAdministrativeAccessRemainsAfterRoleSync(Role $role, array $permissionNames): void
    {
        $affectedUserIds = $role->users()->pluck('users.id')->values()->all();

        if ($affectedUserIds === [] || $this->authorizationManagerExistsExcluding($affectedUserIds)) {
            return;
        }

        $affectedUsers = User::query()
            ->whereKey($affectedUserIds)
            ->with(['permissions:id,name', 'roles.permissions:id,name'])
            ->get();

        foreach ($affectedUsers as $user) {
            $proposedPermissions = $user->permissions->pluck('name')
                ->merge($permissionNames)
                ->merge(
                    $user->roles
                        ->reject(fn (Model $assignedRole): bool => $assignedRole instanceof Role && $assignedRole->is($role))
                        ->flatMap(fn (Model $assignedRole): Collection => $assignedRole instanceof Role
                            ? $assignedRole->permissions->pluck('name')
                            : collect()),
                )
                ->unique();

            if (collect(Permissions::critical())->every(fn (string $permission): bool => $proposedPermissions->contains($permission))) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'authorization' => 'At least one user must retain complete authorization management access.',
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $excludedUserIds
     */
    private function authorizationManagerExistsExcluding(array $excludedUserIds): bool
    {
        return User::query()
            ->whereKeyNot($excludedUserIds)
            ->where(function (Builder $query): void {
                foreach (Permissions::critical() as $permission) {
                    $query->where(function (Builder $permissionQuery) use ($permission): void {
                        $permissionQuery
                            ->whereHas('permissions', fn (Builder $directQuery): Builder => $directQuery->where('name', $permission))
                            ->orWhereHas('roles.permissions', fn (Builder $roleQuery): Builder => $roleQuery->where('name', $permission));
                    });
                }
            })
            ->exists();
    }
}
