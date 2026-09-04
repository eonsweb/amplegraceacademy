<?php

use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

test('login redirects temporary-password users to the required change page', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->post(route('login.store'), [
        'username' => $user->username,
        'password' => 'password',
    ])->assertRedirect(route('password.change-required'));

    $this->assertAuthenticatedAs($user);
});

test('temporary-password users are redirected away from protected pages', function () {
    Permission::findOrCreate(Permissions::DASHBOARD_VIEW);
    $user = User::factory()->mustChangePassword()->create();
    $user->givePermissionTo(Permissions::DASHBOARD_VIEW);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('password.change-required'));
});

test('temporary-password users can view the required change page and log out', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)
        ->get(route('password.change-required'))
        ->assertSee('You are using a temporary password');

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('home'));

    $this->assertGuest();
});

test('changing the temporary password clears the requirement', function () {
    $user = User::factory()->mustChangePassword()->create();

    Livewire::actingAs($user)
        ->test('pages::auth.change-required-password')
        ->set('password', 'new-secure-password')
        ->set('password_confirmation', 'new-secure-password')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $user = $user->fresh();

    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check('new-secure-password', $user->password))->toBeTrue()
        ->and(Hash::check('password', $user->password))->toBeFalse();
});

test('the temporary password cannot be reused', function () {
    $user = User::factory()->mustChangePassword()->create();

    Livewire::actingAs($user)
        ->test('pages::auth.change-required-password')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('save')
        ->assertHasErrors(['password']);

    expect($user->fresh()->must_change_password)->toBeTrue();
});

test('inactive users receive the standard authentication failure', function () {
    $user = User::factory()->inactive()->create();

    $this->post(route('login.store'), [
        'username' => $user->username,
        'password' => 'password',
    ])->assertSessionHasErrors([
        'username' => __('auth.failed'),
    ]);

    $this->assertGuest();
});
