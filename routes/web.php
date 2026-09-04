<?php

use App\Support\Authorization\Permissions;
use Illuminate\Support\Facades\Route;

Route::view('/', 'pages.auth.login')
    ->middleware('guest')
    ->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')
        ->middleware('permission:'.Permissions::DASHBOARD_VIEW)
        ->name('dashboard');
});

require __DIR__.'/settings.php';
