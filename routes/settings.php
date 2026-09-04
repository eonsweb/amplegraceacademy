<?php

use App\Support\Authorization\Permissions;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'password.changed'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified', 'password.changed'])->group(function () {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');

    Route::livewire('settings/system', 'pages::settings.system')
        ->middleware('permission:'.Permissions::SETTINGS_VIEW.'|'.Permissions::SETTINGS_UPDATE)
        ->name('settings.system');

    Route::livewire('settings/users', 'pages::settings.users.index')
        ->middleware('permission:'.Permissions::USERS_VIEW)
        ->name('users.index');

    Route::livewire('settings/users/{user}/authorization', 'pages::settings.users.authorization')
        ->middleware('permission:'.Permissions::USERS_VIEW)
        ->name('users.authorization');

    Route::livewire('settings/roles', 'pages::settings.roles.index')
        ->middleware('permission:'.Permissions::ROLES_VIEW.'|'.Permissions::PERMISSIONS_VIEW)
        ->name('roles.index');

    Route::livewire('settings/roles/{role}', 'pages::settings.roles.edit')
        ->middleware('permission:'.Permissions::ROLES_VIEW.'|'.Permissions::PERMISSIONS_VIEW)
        ->name('roles.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
