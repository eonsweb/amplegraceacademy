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

function seedUserAuthorizationManagement(): void
{
    test()->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class]);
}

test('user access route is forbidden without users view permission', function () {
    seedUserAuthorizationManagement();
    $actor = User::factory()->create();

    $this->actingAs($actor)->get(route('users.index'))->assertForbidden();
});

test('privileged users can assign roles and direct permissions', function () {
    seedUserAuthorizationManagement();
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);
    $subject = User::factory()->create();
    Event::fake([AuthorizationChanged::class]);

    Livewire::actingAs($actor)
        ->test('pages::settings.users.authorization', ['user' => $subject])
        ->set('roleNames', [Roles::PROPRIETOR])
        ->set('directPermissionNames', [Permissions::SETTINGS_UPDATE])
        ->call('save')
        ->assertHasNoErrors();

    $subject = $subject->fresh();

    expect($subject->hasRole(Roles::PROPRIETOR))->toBeTrue()
        ->and($subject->hasDirectPermission(Permissions::SETTINGS_UPDATE))->toBeTrue()
        ->and($subject->can(Permissions::SETTINGS_UPDATE))->toBeTrue();
    Event::assertDispatched(AuthorizationChanged::class, fn (AuthorizationChanged $event): bool => $event->action === 'user.role_assigned');
    Event::assertDispatched(AuthorizationChanged::class, fn (AuthorizationChanged $event): bool => $event->action === 'user.permission_assigned');
});

test('users without assignment permission cannot change another users access', function () {
    seedUserAuthorizationManagement();
    $actor = User::factory()->create();
    $actor->givePermissionTo([Permissions::USERS_VIEW, Permissions::USERS_UPDATE]);
    $subject = User::factory()->create();

    Livewire::actingAs($actor)
        ->test('pages::settings.users.authorization', ['user' => $subject])
        ->set('roleNames', [Roles::TEACHER])
        ->call('save')
        ->assertForbidden();

    expect($subject->fresh()->roles)->toBeEmpty();
});

test('access managers cannot assign roles containing permissions they do not hold', function () {
    seedUserAuthorizationManagement();
    $actor = User::factory()->create();
    $actor->givePermissionTo([
        Permissions::USERS_VIEW,
        Permissions::USERS_UPDATE,
        Permissions::PERMISSIONS_ASSIGN,
    ]);
    $subject = User::factory()->create();

    Livewire::actingAs($actor)
        ->test('pages::settings.users.authorization', ['user' => $subject])
        ->set('roleNames', [Roles::ADMIN])
        ->call('save')
        ->assertHasErrors(['permissionNames']);

    expect($subject->fresh()->roles)->toBeEmpty();
});

test('users cannot change their own authorization assignments', function () {
    seedUserAuthorizationManagement();
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);

    Livewire::actingAs($actor)
        ->test('pages::settings.users.authorization', ['user' => $actor])
        ->set('roleNames', [])
        ->set('directPermissionNames', [])
        ->call('save')
        ->assertHasErrors(['authorization']);

    expect($actor->fresh()->hasRole(Roles::ADMIN))->toBeTrue();
});

test('inherited permissions are not duplicated as direct grants', function () {
    seedUserAuthorizationManagement();
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);
    $subject = User::factory()->create();

    Livewire::actingAs($actor)
        ->test('pages::settings.users.authorization', ['user' => $subject])
        ->set('roleNames', [Roles::TEACHER])
        ->set('directPermissionNames', [Permissions::STUDENTS_VIEW])
        ->call('save')
        ->assertHasNoErrors();

    $subject = $subject->fresh();

    expect($subject->hasDirectPermission(Permissions::STUDENTS_VIEW))->toBeFalse()
        ->and($subject->can(Permissions::STUDENTS_VIEW))->toBeTrue();
});

test('submitted permission names must exist', function () {
    seedUserAuthorizationManagement();
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);
    $subject = User::factory()->create();

    Livewire::actingAs($actor)
        ->test('pages::settings.users.authorization', ['user' => $subject])
        ->set('directPermissionNames', ['permissions.not-real'])
        ->call('save')
        ->assertHasErrors(['directPermissionNames.0']);

    expect($subject->fresh()->permissions)->toBeEmpty();
});
