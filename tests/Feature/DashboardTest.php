<?php

use App\Actions\Fees\VoidPayment;
use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\ClassLevel;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SchoolSetting;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Support\Academic\AcademicContext;
use App\Support\Authorization\Permissions;
use App\Support\DashboardMetrics;
use App\Support\Finance\FinanceSummary;
use App\Support\Reports\AttendanceReports;
use App\Support\Reports\ReportData;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('home'));
});

test('authorized users can visit the dashboard', function () {
    Permission::findOrCreate(Permissions::DASHBOARD_VIEW);
    $user = User::factory()->create();
    $user->givePermissionTo(Permissions::DASHBOARD_VIEW);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertSee([
        'Dashboard',
        'Recent Notices',
        'Upcoming Events',
        'No notices available.',
        'No upcoming events available.',
    ]);
    $response->assertDontSee('Fee Collection Overview')->assertDontSee('Recent Payments');
});

function dashboardActor(?array $permissions = null): User
{
    $permissions ??= [Permissions::STUDENTS_VIEW, Permissions::STAFF_VIEW, Permissions::CLASSES_VIEW,
        Permissions::ATTENDANCE_VIEW, Permissions::FINANCIAL_REPORTS_VIEW, Permissions::PAYMENTS_VIEW];
    $permissions[] = Permissions::DASHBOARD_VIEW;
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

test('empty dashboard uses school timezone currency and honest empty states', function () {
    SchoolSetting::factory()->create(['id' => 1, 'timezone' => 'America/New_York', 'currency_code' => 'USD']);
    $this->travelTo(now('UTC')->setDate(2026, 1, 1)->setTime(2, 0));

    $response = $this->actingAs(dashboardActor())->get(route('dashboard'));

    $response->assertSee('Wednesday, December 31, 2025')->assertSee('No current academic year configured')
        ->assertSee('No current term configured')->assertSee('Not recorded')->assertSee('No payments recorded.')
        ->assertSee('No collections in either month')->assertSee('$0.00')
        ->assertDontSee('Midterm Exams Schedule')->assertDontSee('Parent-Teacher Meeting')->assertDontSee('RCP-2025-0056');
});

test('student metrics count distinct active current enrollments and follow changed academic context', function () {
    $this->travelTo(now()->setDate(2026, 9, 24));
    $user = dashboardActor([Permissions::STUDENTS_VIEW]);
    $year = AcademicYear::factory()->create(['is_current' => true]);
    $enrollment = Enrollment::factory()->create(['academic_year_id' => $year->id, 'enrollment_date' => '2026-09-01']);
    Enrollment::factory()->for($enrollment->student)->create(['academic_year_id' => $year->id, 'status' => 'promoted']);
    Enrollment::factory()->for($enrollment->student)->create();
    Enrollment::factory()->create(['academic_year_id' => $year->id, 'status' => 'withdrawn']);
    Enrollment::factory()->for(Student::factory()->create(['status' => 'inactive']))->create(['academic_year_id' => $year->id]);
    Enrollment::factory()->create(['academic_year_id' => $year->id, 'enrollment_date' => '2026-10-01']);
    $metrics = app(DashboardMetrics::class);

    expect($metrics->forUser($user)['students'])->toBe(['total' => 1, 'new' => 1]);
    Enrollment::factory()->create(['academic_year_id' => $year->id, 'enrollment_date' => '2026-08-01']);
    expect($metrics->forUser($user)['students'])->toBe(['total' => 2, 'new' => 1]);
    app(AcademicContext::class)->setCurrentYear(AcademicYear::factory()->create());
    expect($metrics->forUser($user)['students'])->toBe(['total' => 0, 'new' => 0]);
});

test('teachers are active teaching staff and classes are active global class levels', function () {
    $this->travelTo(now()->setDate(2026, 9, 24));
    Staff::factory()->teacher()->create(['employment_date' => '2026-09-02']);
    Staff::factory()->teacher()->create(['employment_date' => '2026-08-02']);
    Staff::factory()->teacher()->inactive()->create();
    Staff::factory()->create(['role_title' => 'Accountant']);
    Staff::factory()->create(['role_title' => 'Teaching Assistant', 'department' => 'Teaching']);
    Staff::factory()->teacher()->create(['role_title' => ' teacher ', 'employment_date' => '2026-08-02']);
    User::factory()->create();
    ClassLevel::factory()->create(['is_active' => true]);
    ClassLevel::factory()->create(['is_active' => false]);

    $data = app(DashboardMetrics::class)->forUser(dashboardActor());

    expect($data['teachers'])->toBe(['total' => 3, 'new' => 1]);
    expect($data['classes'])->toBe(1);
});

test('collections chart and balances reuse exact finance totals without join multiplication', function () {
    $this->travelTo(now()->setDate(2026, 9, 24));
    $user = dashboardActor();
    $year = AcademicYear::factory()->create(['is_current' => true]);
    $invoice = Invoice::factory()->create(['academic_year_id' => $year->id]);
    InvoiceItem::factory()->for($invoice)->create(['amount' => '100.10', 'unit_amount' => '100.10']);
    InvoiceItem::factory()->for($invoice)->create(['amount' => '50.20', 'unit_amount' => '50.20']);
    foreach (['20.10', '30.20'] as $amount) {
        $payment = Payment::factory()->for($invoice->student)->create(['amount' => $amount, 'payment_date' => '2026-09-01']);
        PaymentAllocation::factory()->for($invoice)->for($payment)->create(['amount' => $amount]);
    }
    Payment::factory()->create(['amount' => '25.15', 'payment_date' => '2026-08-01']);
    $void = Payment::factory()->create(['amount' => '999.00', 'payment_date' => '2026-09-01', 'voided_at' => now(), 'voided_by_user_id' => $user->id, 'void_reason' => 'Duplicate']);
    Payment::factory()->create(['amount' => '999.00', 'payment_date' => '2026-08-01', 'voided_at' => now(), 'voided_by_user_id' => $user->id, 'void_reason' => 'Duplicate']);
    $metrics = app(DashboardMetrics::class);
    $data = $metrics->forUser($user)['finance'];

    expect($data['current'])->toBe('50.30');
    expect($data['previous'])->toBe('25.15');
    expect($data['trend'])->toBe('+100.0% vs previous month');
    expect($data['outstanding'])->toBe('100.00');
    expect($data['current'])->toBe(FinanceSummary::totals(['date_from' => '2026-09-01', 'date_to' => '2026-09-24'])['income']);
    expect(array_column($data['points'], 'amount'))->toBe(['0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '25.15', '50.30']);
    Permission::findOrCreate(Permissions::PAYMENTS_VOID, 'web');
    $user->givePermissionTo(Permissions::PAYMENTS_VOID);
    app(VoidPayment::class)->handle($user, $payment->id, 'Correction');
    expect($metrics->forUser($user)['finance']['current'])->toBe('20.10');
    $this->actingAs($user)->get(route('dashboard'))->assertDontSee($void->receipt_number);
});

test('collection comparisons handle zero periods declines and January rollover', function (string $previous, string $current, string $trend) {
    $this->travelTo(now()->setDate(2026, 1, 24));
    if ($previous !== '0.00') {
        Payment::factory()->create(['payment_date' => '2025-12-31', 'amount' => $previous]);
    }
    if ($current !== '0.00') {
        Payment::factory()->create(['payment_date' => '2026-01-01', 'amount' => $current]);
    }
    $user = dashboardActor();
    $metrics = app(DashboardMetrics::class);

    expect($metrics->forUser($user)['finance']['trend'])->toBe($trend);
    $data = $metrics->forUser($user, 'previous-year')['finance'];
    expect($data['chartYear'])->toBe(2025);
    expect($data['points'][11]['amount'])->toBe($previous);
})->with([
    ['0.00', '0.00', 'No collections in either month'],
    ['0.00', '10.10', 'New this month'],
    ['20.20', '10.10', '-50.0% vs previous month'],
    ['10.10', '0.00', '-100.0% vs previous month'],
]);

test('attendance preserves recorded statuses after withdrawal and never counts unmarked students as absent', function () {
    $this->travelTo(now()->setDate(2026, 9, 24));
    $user = dashboardActor();
    $year = AcademicYear::factory()->create(['is_current' => true]);
    $term = Term::factory()->create(['academic_year_id' => $year->id, 'is_current' => true]);
    $class = ClassLevel::factory()->create();
    foreach (['present', 'absent', 'late', 'excused'] as $status) {
        $enrollment = Enrollment::factory()->create(['academic_year_id' => $year->id, 'class_level_id' => $class->id, 'enrollment_date' => '2026-09-01']);
        Attendance::factory()->for($enrollment)->for($term)->create(['status' => $status, 'attendance_date' => '2026-09-24']);
    }
    Enrollment::factory()->create(['academic_year_id' => $year->id, 'class_level_id' => $class->id]);
    $metrics = app(DashboardMetrics::class);
    $data = $metrics->forUser($user)['attendance'];

    expect($data['records'])->toBe(4);
    expect($data['percentage'])->toBe('50.00');
    expect(array_column($data['segments'], 'count'))->toBe([1, 1, 1, 1]);
    expect($data['percentage'])->toBe(AttendanceReports::summary($user, ['academic_year_id' => (string) $year->id, 'term_id' => (string) $term->id, 'date' => '2026-09-24'])['Attendance %']);
    $enrollment->update(['status' => 'withdrawn']);
    expect($metrics->forUser($user)['attendance']['records'])->toBe(4);
    expect($metrics->forUser($user)['attendance']['percentage'])->toBe('50.00');
    $teacher = dashboardActor([Permissions::STUDENTS_VIEW, Permissions::ATTENDANCE_VIEW, Permissions::ATTENDANCE_RECORD]);
    expect($metrics->forUser($teacher)['attendance']['records'])->toBe(0);
    ClassSubject::factory()->create(['academic_year_id' => $year->id, 'class_level_id' => $class->id, 'staff_id' => $teacher->id]);
    expect($metrics->forUser($teacher)['attendance']['records'])->toBe(4);
    $this->travel(1)->days();
    expect($metrics->forUser($user)['attendance']['percentage'])->toBeNull();
});

test('recent payments are limited ordered and preserve invoice class and student snapshots', function () {
    SchoolSetting::factory()->create(['id' => 1, 'timezone' => 'America/New_York', 'date_format' => 'YYYY-MM-DD']);
    $this->travelTo(now('UTC')->setDate(2026, 9, 24)->setTime(12, 0));
    $user = dashboardActor();
    $first = Payment::factory()->create(['payment_date' => today()->subDay()]);
    $latest = Payment::factory()->count(6)->create(['payment_date' => today()]);
    $invoice = Invoice::factory()->create(['student_id' => $latest->last()->student_id, 'class_name' => 'Historical Class', 'student_name' => 'Historical Student']);
    PaymentAllocation::factory()->for($invoice)->for($latest->last())->create();
    $this->actingAs($user)->get(route('dashboard'))
        ->assertSee('Historical Class')->assertSee('Historical Student')->assertDontSee($first->receipt_number)
        ->assertSee(route('fees.payments'))->assertSee('2026-09-24');

    $rows = app(DashboardMetrics::class)->forUser($user)['recentPayments'];
    expect($rows->modelKeys())->toBe($latest->reverse()->take(5)->values()->modelKeys());
});

test('changing the current term changes attendance without changing enrollment totals', function () {
    $user = dashboardActor();
    $year = AcademicYear::factory()->create(['is_current' => true]);
    $first = Term::factory()->create(['academic_year_id' => $year->id, 'is_current' => true]);
    $next = Term::factory()->second()->create(['academic_year_id' => $year->id]);
    $enrollment = Enrollment::factory()->create(['academic_year_id' => $year->id, 'enrollment_date' => today()->subDay()]);
    Attendance::factory()->for($enrollment)->for($first)->create();
    $metrics = app(DashboardMetrics::class);
    expect($metrics->forUser($user)['attendance']['records'])->toBe(1);

    app(AcademicContext::class)->setCurrentTerm($next);

    $data = $metrics->forUser($user);
    expect($data['attendance']['records'])->toBe(0);
    expect($data['students']['total'])->toBe(1);
    expect($data['academicTerm'])->toBe($next->name);
});

test('restricted dashboard performs no financial queries and rejects invalid periods', function () {
    $user = dashboardActor([]);
    $this->actingAs($user);
    DB::enableQueryLog();
    DB::flushQueryLog();

    $response = $this->get(route('dashboard'));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertDontSee('Fee Collection')->assertDontSee('Outstanding Fees')->assertDontSee('Recent Payments');
    expect(collect($queries)->filter(fn (array $query): bool => (bool) preg_match('/`(?:payments|invoices|invoice_items|payment_allocations|attendances|staff|students)`/', $query['query'])))->toBeEmpty();
    $this->getJson(route('dashboard', ['period' => ['invalid']]))->assertUnprocessable()->assertJsonValidationErrors('period');
    $this->getJson(route('dashboard', ['period' => 'invalid']))->assertUnprocessable()->assertJsonValidationErrors('period');
});

test('dashboard query counts remain bounded when student and payment records grow', function () {
    $user = dashboardActor();
    $year = AcademicYear::factory()->create(['is_current' => true]);
    Term::factory()->create(['academic_year_id' => $year->id, 'is_current' => true]);
    PaymentAllocation::factory()->create();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $measure = function (): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('dashboard'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $baseline = $measure();
    PaymentAllocation::factory()->count(8)->create();
    Enrollment::factory()->count(12)->create(['academic_year_id' => $year->id]);

    expect($measure())->toBe($baseline)->toBeLessThanOrEqual(20);
});

test('attendance matches reports for recorded inactive pupils and pre-enrollment dates while excluding other periods', function () {
    $this->travelTo(now()->setDate(2026, 9, 24));
    $year = AcademicYear::factory()->create(['is_current' => true]);
    $term = Term::factory()->for($year)->create(['is_current' => true]);
    $current = Enrollment::factory()->for($year)->create(['enrollment_date' => '2026-09-01']);
    Attendance::factory()->for($current)->for($term)->create();
    $inactive = Enrollment::factory()->for($year)->for(Student::factory()->create(['status' => 'inactive']))
        ->create(['enrollment_date' => '2026-09-01']);
    Attendance::factory()->for($inactive)->for($term)->create(['status' => 'absent']);
    $future = Enrollment::factory()->for($year)->create(['enrollment_date' => '2026-09-25']);
    Attendance::factory()->for($future)->for($term)->create(['status' => 'absent']);
    Attendance::factory()->create(['status' => 'absent']);
    Attendance::factory()->for($current)->for($term)->create(['attendance_date' => '2026-09-23', 'status' => 'absent']);

    $user = dashboardActor([Permissions::ATTENDANCE_VIEW, Permissions::REPORTS_VIEW]);
    $data = app(DashboardMetrics::class)->forUser($user)['attendance'];

    expect($data['records'])->toBe(3);
    expect($data['percentage'])->toBe('33.33');
    expect(array_column($data['segments'], 'count'))->toBe([1, 2, 0, 0]);
    $filters = ['academic_year_id' => (string) $year->id, 'term_id' => (string) $term->id,
        'date_from' => '2026-09-24', 'date_to' => '2026-09-24'];
    foreach (['attendance-daily', 'attendance-classes'] as $report) {
        $reportFilters = $report === 'attendance-daily'
            ? ['academic_year_id' => (string) $year->id, 'term_id' => (string) $term->id, 'date' => '2026-09-24']
            : $filters;
        $summary = (new ReportData($user, $report, $reportFilters))->summary();
        expect($summary['Records'])->toBe($data['records']);
        expect($summary['Attendance %'])->toBe($data['percentage']);
    }
    foreach ([$inactive, $future] as $enrollment) {
        $summary = (new ReportData($user, 'attendance-students', [...$filters,
            'student' => $enrollment->student->admission_number]))->summary();
        expect($summary)->toMatchArray(['Records' => 1, 'Absent' => 1, 'Attendance %' => '0.00']);
    }
});

test('payment and financial report permissions independently control dashboard data', function (string $permission) {
    $this->travelTo(now()->setDate(2026, 9, 24));
    $payment = Payment::factory()->create(['amount' => '12.34', 'payment_date' => '2026-09-24']);
    Payment::factory()->create(['amount' => '999.99', 'payment_date' => '2026-09-25']);
    $user = dashboardActor([$permission]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    if ($permission === Permissions::PAYMENTS_VIEW) {
        $response->assertSee($payment->receipt_number)->assertDontSee('Fee Collection Overview');
        expect($response['finance'])->toBeNull();
        expect($response['recentPayments']->modelKeys())->toBe([$payment->id]);
    } else {
        $response->assertSee('Fee Collection Overview')->assertDontSee($payment->receipt_number);
        expect($response['recentPayments'])->toBeNull();
        expect($response['finance']['current'])->toBe('12.34');
        expect($response['finance']['points'][8]['amount'])->toBe('12.34');
    }
})->with([Permissions::PAYMENTS_VIEW, Permissions::FINANCIAL_REPORTS_VIEW]);

test('dashboard does not choose an unconfigured term even when attendance exists', function () {
    $this->travelTo(now()->setDate(2026, 9, 24)->startOfDay());
    $year = AcademicYear::factory()->create(['is_current' => true]);
    $term = Term::factory()->for($year)->create(['is_current' => null]);
    $enrollment = Enrollment::factory()->for($year)->create(['enrollment_date' => '2026-09-01']);
    Attendance::factory()->for($enrollment)->for($term)->create();

    $response = $this->actingAs(dashboardActor())->get(route('dashboard'));

    $response->assertSee('No current term configured')->assertSee('Not recorded');
    expect($response['academicYear'])->toBe($year->name);
    expect($response['academicTerm'])->toBeNull();
    expect($response['students']['total'])->toBe(1);
    expect($response['attendance']['records'])->toBe(0);
    expect($response['attendance']['percentage'])->toBeNull();
});

test('dashboard period selector changes the chart without changing current month collections', function () {
    $this->travelTo(now()->setDate(2026, 1, 24)->startOfDay());
    Payment::factory()->create(['payment_date' => '2025-12-31', 'amount' => '25.15']);
    Payment::factory()->create(['payment_date' => '2026-01-01', 'amount' => '50.30']);

    $response = $this->actingAs(dashboardActor([Permissions::FINANCIAL_REPORTS_VIEW]))
        ->get(route('dashboard', ['period' => 'previous-year']));

    $response->assertSee('Calendar year 2025');
    expect($response['period'])->toBe('previous-year');
    expect($response['finance']['current'])->toBe('50.30');
    expect(array_column($response['finance']['points'], 'amount'))->toBe([
        '0.00', '0.00', '0.00', '0.00', '0.00', '0.00',
        '0.00', '0.00', '0.00', '0.00', '0.00', '25.15',
    ]);
});

test('one payment across academic periods is collected once and displays each historical class', function () {
    $this->travelTo(now()->setDate(2026, 9, 24)->startOfDay());
    $student = Student::factory()->create();
    $payment = Payment::factory()->for($student)->create(['amount' => '30.30', 'payment_date' => '2026-09-24']);
    foreach (['Former Class' => '10.10', 'Later Class' => '20.20'] as $className => $amount) {
        $enrollment = Enrollment::factory()->for($student)->create();
        $invoice = Invoice::factory()->for($enrollment)->create(['class_name' => $className]);
        InvoiceItem::factory()->for($invoice)->create(['amount' => $amount, 'unit_amount' => $amount]);
        PaymentAllocation::factory()->for($payment)->for($invoice)->create(['amount' => $amount]);
    }
    $currentYear = AcademicYear::factory()->create(['is_current' => true]);
    $currentClass = ClassLevel::factory()->create(['name' => 'Current Unrelated Class']);
    Enrollment::factory()->for($student)->for($currentYear)->for($currentClass)->create();

    $response = $this->actingAs(dashboardActor())->get(route('dashboard'));

    $response->assertSee('Former Class')->assertSee('Later Class')->assertDontSee('Current Unrelated Class');
    expect($response['recentPayments']->modelKeys())->toBe([$payment->id]);
    expect($response['finance']['current'])->toBe('30.30');
    expect($response['finance']['points'][8]['amount'])->toBe('30.30');
    expect($response['finance']['current'])->toBe(FinanceSummary::totals([
        'date_from' => '2026-09-01', 'date_to' => '2026-09-24',
    ])['income']);
});

test('collections use the school calendar month at a UTC year boundary', function () {
    SchoolSetting::factory()->create(['id' => 1, 'timezone' => 'America/New_York']);
    $this->travelTo(now('UTC')->setDate(2026, 1, 1)->setTime(2, 0));
    Payment::factory()->create(['payment_date' => '2025-11-30', 'amount' => '10.10']);
    $december = Payment::factory()->create(['payment_date' => '2025-12-31', 'amount' => '20.20']);
    $january = Payment::factory()->create(['payment_date' => '2026-01-01', 'amount' => '999.99']);

    $response = $this->actingAs(dashboardActor())->get(route('dashboard'));

    $response->assertSee('Wednesday, December 31, 2025')->assertSee($december->receipt_number)
        ->assertDontSee($january->receipt_number);
    expect($response['finance']['current'])->toBe('20.20');
    expect($response['finance']['previous'])->toBe('10.10');
    expect($response['finance']['trend'])->toBe('+100.0% vs previous month');
    expect($response['finance']['chartYear'])->toBe(2025);
    expect($response['finance']['points'][11]['amount'])->toBe('20.20');
});

test('attendance today follows the school date across a UTC year boundary', function () {
    SchoolSetting::factory()->create(['id' => 1, 'timezone' => 'America/New_York']);
    $this->travelTo(now('UTC')->setDate(2026, 1, 1)->setTime(2, 0));
    $year = AcademicYear::factory()->create(['is_current' => true]);
    $term = Term::factory()->for($year)->create(['is_current' => true]);
    $enrollment = Enrollment::factory()->for($year)->create(['enrollment_date' => '2025-09-01']);
    Attendance::factory()->for($enrollment)->for($term)->create(['attendance_date' => '2025-12-31', 'status' => 'late']);
    Attendance::factory()->for($enrollment)->for($term)->create(['attendance_date' => '2026-01-01', 'status' => 'absent']);

    $response = $this->actingAs(dashboardActor([Permissions::ATTENDANCE_VIEW]))->get(route('dashboard'));

    $response->assertSee('Wednesday, December 31, 2025');
    expect($response['attendance']['records'])->toBe(1);
    expect($response['attendance']['percentage'])->toBe('100.00');
    expect(array_column($response['attendance']['segments'], 'count'))->toBe([0, 0, 1, 0]);
});
