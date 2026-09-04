<?php

use App\Models\User;
use Livewire\Livewire;

test('an authenticated user can persist a supported theme preference', function (string $theme) {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.appearance')
        ->set('theme', $theme)
        ->assertHasNoErrors();

    expect($user->fresh()->theme)->toBe($theme);
})->with([
    'light' => 'light',
    'dark' => 'dark',
    'system' => 'system',
]);

test('an arbitrary theme preference is rejected', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.appearance')
        ->set('theme', 'midnight')
        ->assertHasErrors(['theme']);

    expect($user->fresh()->theme)->toBe('system');
});

test('the saved account theme is initialized before application assets', function () {
    $user = User::factory()->create(['theme' => 'dark']);

    $response = $this->actingAs($user)->get(route('appearance.edit'));

    $response->assertSee("window.localStorage.setItem('flux.appearance', appearance)", false)
        ->assertSee("const appearance = 'dark'", false);
});
