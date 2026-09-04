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

Route::middleware(['auth', 'verified', 'password.changed'])
    ->prefix('academic')
    ->name('academic.')
    ->group(function () {
        Route::get('/', function () {
            $user = request()->user();

            abort_if($user === null, 403);

            if ($user->can(Permissions::CLASSES_VIEW)) {
                return to_route('academic.years.index');
            }

            if ($user->can(Permissions::SUBJECTS_VIEW)) {
                return to_route('academic.subjects.index');
            }

            abort(403);
        })->middleware('permission:'.Permissions::CLASSES_VIEW.'|'.Permissions::SUBJECTS_VIEW)->name('index');

        Route::livewire('years', 'pages::academic.years.index')
            ->middleware('permission:'.Permissions::CLASSES_VIEW)
            ->name('years.index');
        Route::livewire('terms', 'pages::academic.terms.index')
            ->middleware('permission:'.Permissions::CLASSES_VIEW)
            ->name('terms.index');
        Route::livewire('class-levels', 'pages::academic.class-levels.index')
            ->middleware('permission:'.Permissions::CLASSES_VIEW)
            ->name('class-levels.index');
        Route::livewire('subjects', 'pages::academic.subjects.index')
            ->middleware('permission:'.Permissions::SUBJECTS_VIEW)
            ->name('subjects.index');
        Route::livewire('class-subjects', 'pages::academic.class-subjects.index')
            ->middleware('permission:'.Permissions::CLASSES_VIEW.'|'.Permissions::SUBJECTS_VIEW)
            ->name('class-subjects.index');
    });

require __DIR__.'/settings.php';
