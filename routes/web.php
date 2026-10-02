<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PaymentReceiptController;
use App\Http\Controllers\ReportExportController;
use App\Support\Authorization\Permissions;
use Illuminate\Support\Facades\Route;

Route::view('/', 'pages.auth.login')
    ->middleware('guest')
    ->name('home');

Route::livewire('password/change-required', 'pages::auth.change-required-password')
    ->middleware('auth')
    ->name('password.change-required');

Route::middleware(['auth', 'verified', 'password.changed'])->group(function () {
    Route::livewire('assessments', 'pages::assessments.index')->middleware('permission:'.Permissions::ASSESSMENTS_VIEW)->name('assessments.index');
    Route::livewire('assessments/create', 'pages::assessments.form')->middleware('permission:'.Permissions::ASSESSMENTS_CREATE)->name('assessments.create');
    Route::livewire('assessments/{assessment}/edit', 'pages::assessments.form')->middleware('permission:'.Permissions::ASSESSMENTS_UPDATE)->name('assessments.edit');
    Route::livewire('assessments/{assessment}/scores', 'pages::assessments.scores')->middleware('permission:'.Permissions::ASSESSMENTS_VIEW)->name('assessments.scores');
    Route::livewire('results', 'pages::results.index')->middleware('permission:'.Permissions::RESULTS_VIEW)->name('results.index');
    Route::livewire('results/students/{student}', 'pages::results.student')->middleware('permission:'.Permissions::RESULTS_VIEW)->name('results.student');

    Route::livewire('attendance', 'pages::attendance.class-attendance')
        ->middleware('permission:'.Permissions::ATTENDANCE_VIEW)
        ->name('attendance.index');
    Route::livewire('attendance/history', 'pages::attendance.attendance-history')
        ->middleware('permission:'.Permissions::ATTENDANCE_VIEW)
        ->name('attendance.history');

    Route::get('dashboard', DashboardController::class)
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

Route::middleware(['auth', 'verified', 'password.changed'])->prefix('fees')->name('fees.')->group(function () {
    Route::get('/', function () {
        foreach ([
            Permissions::FEES_VIEW => 'fees.overview',
            Permissions::FEES_MANAGE => 'fees.structures',
            Permissions::FEE_TYPES_MANAGE => 'fees.types',
            Permissions::INVOICES_VIEW => 'fees.invoices',
            Permissions::PAYMENTS_RECORD => 'fees.record-payment',
            Permissions::PAYMENTS_VIEW => 'fees.payments',
            Permissions::BALANCES_VIEW => 'fees.outstanding',
        ] as $permission => $route) {
            if (request()->user()?->can($permission)) {
                return to_route($route);
            }
        }
        abort(403);
    })->name('index');
    foreach ([
        'overview' => Permissions::FEES_VIEW,
        'types' => Permissions::FEE_TYPES_MANAGE,
        'structures' => Permissions::FEES_MANAGE,
        'invoices' => Permissions::INVOICES_VIEW,
        'record-payment' => Permissions::PAYMENTS_RECORD,
        'payments' => Permissions::PAYMENTS_VIEW,
        'outstanding' => Permissions::BALANCES_VIEW,
    ] as $page => $permission) {
        Route::livewire($page, 'pages::fees.'.$page)->middleware('permission:'.$permission)->name($page);
    }
    Route::livewire('invoices/{invoice}', 'pages::fees.invoice')->middleware('permission:'.Permissions::INVOICES_VIEW)->name('invoice');
    Route::livewire('students/{student}', 'pages::fees.account')->middleware('permission:'.Permissions::BALANCES_VIEW)->name('account');
    Route::get('receipts/{payment}', PaymentReceiptController::class)->middleware('permission:'.Permissions::RECEIPTS_PRINT)->name('receipt');
});

Route::middleware(['auth', 'verified', 'password.changed'])->group(function () {
    Route::livewire('expenses', 'pages::expenses.index')->middleware('permission:'.Permissions::EXPENSES_VIEW)->name('expenses.index');
    Route::livewire('expenses/create', 'pages::expenses.form')->middleware('permission:'.Permissions::EXPENSES_CREATE)->name('expenses.create');
    Route::livewire('expenses/categories', 'pages::expenses.categories')->middleware('permission:'.Permissions::EXPENSE_CATEGORIES_MANAGE)->name('expenses.categories');
    Route::livewire('expenses/{expense}/edit', 'pages::expenses.form')->middleware('permission:'.Permissions::EXPENSES_UPDATE)->name('expenses.edit');
    Route::livewire('expenses/{expense}', 'pages::expenses.show')->middleware('permission:'.Permissions::EXPENSES_VIEW)->name('expenses.show');
    Route::livewire('finance/overview', 'pages::finance.overview')->middleware('permission:'.Permissions::FINANCIAL_REPORTS_VIEW)->name('finance.overview');
});

require __DIR__.'/settings.php';

Route::middleware(['auth', 'verified', 'password.changed', 'permission:'.Permissions::REPORTS_VIEW])->prefix('reports')->name('reports.')->group(function () {
    Route::livewire('/', 'pages::reports.index')->name('index');
    Route::get('{report}/{format}', ReportExportController::class)->name('export');
    Route::livewire('{report}', 'pages::reports.show')->name('show');
});
