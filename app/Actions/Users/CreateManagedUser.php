<?php

namespace App\Actions\Users;

use App\Events\UserManagementChanged;
use App\Models\Staff;
use App\Models\User;
use App\Support\Authorization\AuthorizationSafety;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateManagedUser
{
    public const TEMPORARY_PASSWORD = 'password';

    /**
     * @param  array{name: string, username: string, email: string, is_active: bool}  $data
     * @param  list<string>  $roleNames
     */
    public function handle(User $actor, array $data, array $roleNames, AuthorizationSafety $safety, ?Staff $staff = null): User
    {
        $safety->ensureRolesAndPermissionsMayBeGranted($actor, $roleNames, []);

        $user = DB::transaction(function () use ($data, $roleNames, $staff): User {
            $createdUser = User::query()->create([
                ...$data,
                'staff_id' => $staff?->id,
                'password' => Hash::make(self::TEMPORARY_PASSWORD),
                'must_change_password' => true,
            ]);

            $createdUser->syncRoles($roleNames);

            return $createdUser;
        });

        UserManagementChanged::dispatch('user.created', $actor->id, $user->id, [
            'roles' => $roleNames,
            'is_active' => $data['is_active'],
            'staff_id' => $staff?->id,
        ]);

        return $user;
    }
}
