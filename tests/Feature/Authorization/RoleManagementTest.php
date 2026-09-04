<?php

use App\Events\AuthorizationChanged;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Authorization\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function seedRoleManagementAuthorization(): void
{
    test()->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class]);
}

test('role management route is forbidden without a viewing permission', function () {
    seedRoleManagementAuthorization();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('roles.index'))->assertForbidden();
});

test('privileged users can create custom roles', function () {
    seedRoleManagementAuthorization();
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);
    Event::fake([AuthorizationChanged::class]);

    Livewire::actingAs($actor)
        ->test('pages::settings.roles.index')
        ->set('newRoleName', 'Accountant')
        ->call('createRole')
        ->assertHasNoErrors();

    expect(Role::findByName('Accountant')->name)->toBe('Accountant');
    Event::assertDispatched(AuthorizationChanged::class, fn (AuthorizationChanged $event): bool => $event->action === 'role.created');
});

test('users without role creation permission cannot create roles', function () {
    seedRoleManagementAuthorization();
    $actor = User::factory()->create();
    $actor->givePermissionTo(Permissions::ROLES_VIEW);

    Livewire::actingAs($actor)
        ->test('pages::settings.roles.index')
        ->set('newRoleName', 'Accountant')
        ->call('createRole')
        ->assertForbidden();

    expect(Role::query()->where('name', 'Accountant')->exists())->toBeFalse();
});

test('authorized users can assign grouped permissions to custom roles', function () {
    seedRoleManagementAuthorization();
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);
    $role = Role::create(['name' => 'Accountant', 'guard_name' => 'web']);

    Livewire::actingAs($actor)
        ->test('pages::settings.roles.edit', ['role' => $role])
        ->set('permissionNames', [Permissions::FEES_VIEW, Permissions::PAYMENTS_VIEW])
        ->call('save')
        ->assertHasNoErrors();

    expect($role->fresh()->hasAllPermissions([Permissions::FEES_VIEW, Permissions::PAYMENTS_VIEW]))->toBeTrue();
});

test('roles assigned to users cannot be deleted', function () {
    seedRoleManagementAuthorization();
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);
    $assignedUser = User::factory()->create();
    $role = Role::create(['name' => 'Accountant', 'guard_name' => 'web']);
    $assignedUser->assignRole($role);

    Livewire::actingAs($actor)
        ->test('pages::settings.roles.index')
        ->call('deleteRole', $role->id)
        ->assertHasErrors(['role']);

    expect($role->fresh())->not->toBeNull();
});

test('eligible custom roles can be deleted', function () {
    seedRoleManagementAuthorization();
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);
    $role = Role::create(['name' => 'Librarian', 'guard_name' => 'web']);

    Livewire::actingAs($actor)
        ->test('pages::settings.roles.index')
        ->call('deleteRole', $role->id)
        ->assertHasNoErrors();

    expect($role->fresh())->toBeNull();
});

test('permission managers cannot grant permissions they do not hold', function () {
    seedRoleManagementAuthorization();
    $actor = User::factory()->create();
    $actor->givePermissionTo([
        Permissions::ROLES_VIEW,
        Permissions::ROLES_UPDATE,
        Permissions::PERMISSIONS_ASSIGN,
        Permissions::USERS_UPDATE,
    ]);
    $role = Role::create(['name' => 'Librarian', 'guard_name' => 'web']);

    Livewire::actingAs($actor)
        ->test('pages::settings.roles.edit', ['role' => $role])
        ->set('permissionNames', [Permissions::FEES_VIEW])
        ->call('save')
        ->assertHasErrors(['permissionNames']);

    expect($role->fresh()->permissions)->toBeEmpty();
});

test('the last authorization manager cannot remove critical role access', function () {
    seedRoleManagementAuthorization();
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);
    $adminRole = Role::findByName(Roles::ADMIN);
    $permissionsWithoutAssignment = collect(Permissions::all())
        ->reject(fn (string $permission): bool => $permission === Permissions::PERMISSIONS_ASSIGN)
        ->values()
        ->all();

    Livewire::actingAs($actor)
        ->test('pages::settings.roles.edit', ['role' => $adminRole])
        ->set('permissionNames', $permissionsWithoutAssignment)
        ->call('save')
        ->assertHasErrors(['authorization']);

    expect($adminRole->fresh()->hasPermissionTo(Permissions::PERMISSIONS_ASSIGN))->toBeTrue();
});
