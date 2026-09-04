<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Features;

test('the public homepage displays the username login form', function () {
    $response = $this->get(route('home'));

    $response
        ->assertOk()
        ->assertSee('Log in to your account')
        ->assertSee('Enter your username and password below to log in')
        ->assertSee('name="username"', false)
        ->assertSee('autocomplete="username"', false)
        ->assertDontSee('name="email"', false)
        ->assertDontSee('Sign up')
        ->assertDontSee('passkey', false);
});

test('the Fortify login route uses the same login form', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('name="username"', false);
});

test('authenticated users visiting the homepage are redirected to the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});

test('users can authenticate with a normalized username', function () {
    $user = User::factory()->create();

    $this->get(route('home'));
    $sessionId = session()->getId();

    $response = $this->post(route('login.store'), [
        'username' => '  '.strtoupper($user->username).'  ',
        'password' => 'password',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    expect(session()->getId())->not->toBe($sessionId);
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'username' => $user->username,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors([
        'username' => __('auth.failed'),
    ]);

    $this->assertGuest();
});

test('email can not be used as the authentication identifier', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'username' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrorsIn('username');

    $this->assertGuest();
});

test('remember me queues the authentication recaller cookie', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'username' => $user->username,
        'password' => 'password',
        'remember' => 'on',
    ])->assertCookie(Auth::guard('web')->getRecallerName());

    $this->assertAuthenticatedAs($user);
});

test('login attempts remain rate limited', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => 'wrong-password',
        ]);
    }

    $this->post(route('login.store'), [
        'username' => $user->username,
        'password' => 'wrong-password',
    ])->assertTooManyRequests();

    $this->assertGuest();
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login.store'), [
        'username' => $user->username,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});
