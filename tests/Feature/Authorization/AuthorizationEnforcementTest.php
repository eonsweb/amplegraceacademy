<?php

use App\Models\User;
use App\Support\Authorization\Permissions;
use Spatie\Permission\Models\Permission;

test('dashboard access is forbidden without its permission', function () {
    Permission::findOrCreate(Permissions::DASHBOARD_VIEW);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
});

test('dashboard access is granted through a direct permission', function () {
    Permission::findOrCreate(Permissions::DASHBOARD_VIEW);
    $user = User::factory()->create();
    $user->givePermissionTo(Permissions::DASHBOARD_VIEW);

    $this->actingAs($user)->get(route('dashboard'))->assertSee('Fee Collection Overview');
});

test('navigation hides authorization links the user cannot access', function () {
    Permission::findOrCreate(Permissions::DASHBOARD_VIEW);
    Permission::findOrCreate(Permissions::USERS_VIEW);
    $user = User::factory()->create();
    $user->givePermissionTo(Permissions::DASHBOARD_VIEW);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertDontSee(route('users.index'))
        ->assertDontSee('Roles &amp; Permissions', false);
});

test('navigation shows user access to users with its permission', function () {
    Permission::findOrCreate(Permissions::DASHBOARD_VIEW);
    Permission::findOrCreate(Permissions::USERS_VIEW);
    $user = User::factory()->create();
    $user->givePermissionTo([Permissions::DASHBOARD_VIEW, Permissions::USERS_VIEW]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSee(route('users.index'))
        ->assertSee('Users');
});
