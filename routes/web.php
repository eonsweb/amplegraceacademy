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
    Route::livewire('attendance', 'pages::attendance.class-attendance')
        ->middleware('permission:'.Permissions::ATTENDANCE_VIEW)
        ->name('attendance.index');
    Route::livewire('attendance/history', 'pages::attendance.attendance-history')
        ->middleware('permission:'.Permissions::ATTENDANCE_VIEW)
        ->name('attendance.history');

    Route::view('dashboard', 'dashboard')
        ->middleware('permission:'.Permissions::DASHBOARD_VIEW)
        ->name('dashboard');

    Route::livewire('students', 'pages::students.index')
        ->middleware('permission:'.Permissions::STUDENTS_VIEW)
        ->name('students.index');
    Route::livewire('students/create', 'pages::students.create')
        ->middleware('permission:'.Permissions::STUDENTS_CREATE)
        ->name('students.create');
    Route::livewire('students/{student}', 'pages::students.show')
        ->middleware('permission:'.Permissions::STUDENTS_VIEW)
        ->name('students.show');
    Route::livewire('students/{student}/edit', 'pages::students.edit')
        ->middleware('permission:'.Permissions::STUDENTS_UPDATE)
        ->name('students.edit');

    Route::livewire('guardians', 'pages::guardians.index')
        ->middleware('permission:'.Permissions::GUARDIANS_VIEW)
        ->name('guardians.index');
    Route::livewire('guardians/create', 'pages::guardians.create')
        ->middleware('permission:'.Permissions::GUARDIANS_CREATE)
        ->name('guardians.create');
    Route::livewire('guardians/{guardian}', 'pages::guardians.show')
        ->middleware('permission:'.Permissions::GUARDIANS_VIEW)
        ->name('guardians.show');
    Route::livewire('guardians/{guardian}/edit', 'pages::guardians.edit')
        ->middleware('permission:'.Permissions::GUARDIANS_UPDATE)
        ->name('guardians.edit');

    Route::livewire('staff', 'pages::staff.index')
        ->middleware('permission:'.Permissions::STAFF_VIEW)
        ->name('staff.index');
    Route::livewire('staff/create', 'pages::staff.create')
        ->middleware('permission:'.Permissions::STAFF_CREATE)
        ->name('staff.create');
    Route::livewire('staff/{staff}', 'pages::staff.show')
        ->middleware('permission:'.Permissions::STAFF_VIEW)
        ->name('staff.show');
    Route::livewire('staff/{staff}/edit', 'pages::staff.edit')
        ->middleware('permission:'.Permissions::STAFF_UPDATE)
        ->name('staff.edit');
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
