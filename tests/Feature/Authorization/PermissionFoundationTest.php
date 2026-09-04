<?php

use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Authorization\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function seedAuthorization(): void
{
    test()->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class]);
}

test('users inherit permissions assigned to their role', function () {
    seedAuthorization();
    $user = User::factory()->create();
    $user->assignRole(Roles::TEACHER);

    expect($user->can(Permissions::ATTENDANCE_RECORD))->toBeTrue()
        ->and($user->can(Permissions::SETTINGS_UPDATE))->toBeFalse();
});

test('users can receive and revoke direct permissions', function () {
    seedAuthorization();
    $user = User::factory()->create();
    $user->assignRole(Roles::PROPRIETOR);
    $user->givePermissionTo(Permissions::SETTINGS_UPDATE);

    expect($user->can(Permissions::SETTINGS_UPDATE))->toBeTrue()
        ->and($user->getDirectPermissions()->pluck('name')->all())->toContain(Permissions::SETTINGS_UPDATE);

    $user->revokePermissionTo(Permissions::SETTINGS_UPDATE);

    expect($user->fresh()->can(Permissions::SETTINGS_UPDATE))->toBeFalse();
});

test('authorization seeders are idempotent and assign the initial role mappings', function () {
    seedAuthorization();
    seedAuthorization();

    $admin = Role::findByName(Roles::ADMIN);
    $teacher = Role::findByName(Roles::TEACHER);

    expect(Permission::query()->count())->toBe(count(Permissions::all()))
        ->and(Role::query()->count())->toBe(count(Roles::initial()))
        ->and($admin->permissions()->count())->toBe(count(Permissions::all()))
        ->and($teacher->hasPermissionTo(Permissions::ASSESSMENTS_RECORD_SCORES))->toBeTrue()
        ->and($teacher->hasPermissionTo(Permissions::FEES_VIEW))->toBeFalse();
});
