<?php

use App\Support\Authorization\Permissions;
use Illuminate\Support\Facades\Route;

Route::view('/', 'pages.auth.login')
    ->middleware('guest')
    ->name('home');

Route::livewire('password/change-required', 'pages::auth.change-required-password')
    ->middleware('auth')
    ->name('password.change-required');

Route::middleware(['auth', 'verified', 'password.changed'])->group(function () {
    Route::view('dashboard', 'dashboard')
        ->middleware('permission:'.Permissions::DASHBOARD_VIEW)
        ->name('dashboard');
});

require __DIR__.'/settings.php';
