<?php

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Attendance;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SchoolSetting;
use App\Models\Term;
use App\Models\User;
use App\Support\Academic\ResultCalculator;
use App\Support\Authorization\Permissions;
use App\Support\Finance\FinanceSummary;
use App\Support\Reports\FinancialReports;
use App\Support\Reports\ReportCatalog;
use App\Support\Reports\ReportData;
use App\Support\Reports\ReportFilters;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

function reportsActor(?array $permissions = null): User
{
    $permissions ??= [Permissions::REPORTS_VIEW, Permissions::STUDENTS_VIEW, Permissions::RESULTS_VIEW, Permissions::RESULTS_VIEW_ALL, Permissions::ATTENDANCE_VIEW, Permissions::FINANCIAL_REPORTS_VIEW];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

test('report routes and exports enforce authentication and domain permissions', function () {
    $this->get(route('reports.index'))->assertRedirect(route('home'));
    $this->actingAs(User::factory()->create())->get(route('reports.index'))->assertForbidden();
    $user = reportsActor([Permissions::REPORTS_VIEW, Permissions::STUDENTS_VIEW]);
    $this->actingAs($user)->get(route('reports.index'))->assertSee('Student Enrollment')->assertDontSee('Financial Summary');
    $this->get(route('reports.show', 'enrollment'))->assertSee('Student Enrollment');
    $this->get(route('reports.show', 'financial-summary'))->assertForbidden();
    foreach (['csv', 'print'] as $format) {
        $this->get(route('reports.export', ['report' => 'financial-summary', 'format' => $format]))->assertForbidden();
    }
    $this->get(route('reports.show', 'unknown'))->assertNotFound();
    $user->revokePermissionTo(Permissions::REPORTS_VIEW);
    Livewire::actingAs($user)->test('pages::reports.index')->assertForbidden();
});

test('all report screens render with useful empty or required-filter states', function (string $report) {
    $this->actingAs(reportsActor())->get(route('reports.show', $report))->assertSee(ReportCatalog::get($report)['title']);
})->with(array_keys(ReportCatalog::all()));

test('historical enrollment filters and class counts survive later placement changes', function () {
    $user = reportsActor();
    $past = Enrollment::factory()->create(['status' => 'promoted']);
    Enrollment::factory()->for($past->student)->create();
    $filters = ['academic_year_id' => (string) $past->academic_year_id, 'class_level_id' => (string) $past->class_level_id, 'status' => 'promoted'];

    $report = new ReportData($user, 'enrollment', $filters);
    expect($report->page(10)->pluck('id')->all())->toBe([$past->id]);
    expect($report->summary())->toMatchArray(['Students' => 1, 'Enrollments' => 1, 'Active enrollments' => 0]);
    unset($filters['status']);
    $row = (new ReportData($user, 'class-enrollment', $filters))->page(10)->first();
    expect((int) $row->students)->toBe(1);
    expect((int) $row->other)->toBe(1);
});

test('assessment and class reports share authoritative totals and preserve incomplete historical results', function () {
    $user = reportsActor();
    $assessment = Assessment::factory()->create(['maximum_score' => '20.00']);
    $enrollment = Enrollment::factory()->create(['academic_year_id' => $assessment->academic_year_id, 'class_level_id' => $assessment->class_level_id, 'status' => 'withdrawn']);
    $missing = Enrollment::factory()->create(['academic_year_id' => $assessment->academic_year_id, 'class_level_id' => $assessment->class_level_id]);
    AssessmentScore::factory()->for($assessment)->for($enrollment)->create(['score' => '15.00']);
    $filters = array_map(strval(...), $assessment->only(['academic_year_id', 'term_id', 'class_level_id', 'subject_id']));

    $rows = (new ReportData($user, 'class-results', $filters))->page(10)->keyBy('id');
    $authoritative = app(ResultCalculator::class)->forClass($user, $assessment->academic_year_id, $assessment->term_id, $assessment->class_level_id, collect([$enrollment, $missing]), $assessment->subject_id);
    expect($rows[$enrollment->id]->percentage)->toBe(75.0)->toBe($authoritative[$enrollment->id]['percentage']);
    expect($rows[$missing->id]->percentage)->toBeNull();
    expect($rows[$missing->id]->status)->toBe('Incomplete');
    $assessmentRows = (new ReportData($user, 'assessment-results', [...$filters, 'assessment_id' => (string) $assessment->id]))->page(10)->keyBy('id');
    expect($assessmentRows[$enrollment->id]->percentage)->toBe(75.0);
    expect($assessmentRows[$missing->id]->status)->toBe('Incomplete');
    $subject = (new ReportData($user, 'subject-performance', $filters))->page(10)->first();
    expect((string) $subject->percentage)->toBe('75.00');
    expect((int) $subject->records)->toBe(1);
});

test('teacher reports and exports stay within assigned classes and subjects', function () {
    $teacher = reportsActor([Permissions::REPORTS_VIEW, Permissions::RESULTS_VIEW, Permissions::ATTENDANCE_VIEW, Permissions::ATTENDANCE_RECORD]);
    $own = Assessment::factory()->create();
    ClassSubject::query()->where('academic_year_id', $own->academic_year_id)->update(['staff_id' => $teacher->id]);
    $other = Assessment::factory()->create();
    AssessmentScore::factory()->for($own)->create();
    AssessmentScore::factory()->for($other)->create();
    $rows = (new ReportData($teacher, 'subject-performance', []))->page(10);
    expect($rows->pluck('subject_name')->all())->toBe([$own->subject->name]);
    $this->actingAs($teacher)->getJson(route('reports.export', ['report' => 'assessment-results', 'format' => 'csv', 'filters' => ['assessment_id' => $other->id]]))->assertUnprocessable()->assertJsonValidationErrors('assessment_id');
    Attendance::factory()->create();
    expect((new ReportData($teacher, 'attendance-classes', []))->page(10)->total())->toBe(0);
});

test('attendance aggregates apply historical class dates student and status filters safely', function () {
    $user = reportsActor();
    SchoolSetting::factory()->create(['id' => 1, 'date_format' => 'DD/MM/YYYY']);
    $enrollment = Enrollment::factory()->create(['status' => 'graduated']);
    $term = Term::factory()->create(['academic_year_id' => $enrollment->academic_year_id]);
    foreach (['present', 'late', 'absent', 'excused'] as $index => $status) {
        Attendance::factory()->for($enrollment)->for($term)->create(['attendance_date' => '2026-01-0'.($index + 1), 'status' => $status]);
    }
    $filters = ['academic_year_id' => (string) $enrollment->academic_year_id, 'class_level_id' => (string) $enrollment->class_level_id, 'date_from' => '2026-01-01', 'date_to' => '2026-01-04'];
    $report = new ReportData($user, 'attendance-classes', $filters);
    expect($report->summary())->toMatchArray(['Records' => 4, 'Present' => 1, 'Absent' => 1, 'Late' => 1, 'Excused' => 1, 'Attendance %' => '50.00']);
    expect((string) $report->page(10)->first()->percentage)->toBe('50.00');
    $daily = new ReportData($user, 'attendance-daily', ['date' => '2026-01-02', 'status' => 'late']);
    expect($daily->page(10)->total())->toBe(1);
    $student = new ReportData($user, 'attendance-students', ['student' => $enrollment->student->admission_number, 'date_from' => '2026-01-03', 'date_to' => '2026-01-04']);
    expect($student->summary())->toMatchArray(['Records' => 2, 'Attendance %' => '0.00']);
    $filteredStudent = new ReportData($user, 'attendance-students', [...$student->filters,
        'class_level_id' => (string) $enrollment->class_level_id, 'status' => 'absent']);
    expect($filteredStudent->page(10)->pluck('status')->all())->toBe(['absent']);
    expect($filteredStudent->summary())->toMatchArray(['Records' => 1, 'Absent' => 1]);
    $otherEnrollment = Enrollment::factory()->create();
    expect((new ReportData($user, 'attendance-students', [...$filteredStudent->filters,
        'class_level_id' => (string) $otherEnrollment->class_level_id]))->page(10)->total())->toBe(0);
    expect((new ReportData($user, 'attendance-daily', [...$daily->filters,
        'student' => $otherEnrollment->student->admission_number]))->page(10)->total())->toBe(0);
    $this->actingAs($user);
    foreach (['csv', 'print'] as $format) {
        $response = $this->get(route('reports.export', ['report' => 'attendance-students', 'format' => $format, 'filters' => $filteredStudent->filters]));
        $response->assertOk();
        expect($response->streamedContent())->toContain('03/01/2026')->not->toContain('04/01/2026');
    }
    expect((new ReportData($user, 'attendance-daily', ['date' => '2025-01-01']))->summary())->toMatchArray(['Records' => 0, 'Attendance %' => null]);
});

test('financial reports avoid join multiplication and exclude voids and drafts with exact decimals', function () {
    $user = reportsActor();
    $partial = Invoice::factory()->create();
    $full = Invoice::factory()->create();
    $unpaid = Invoice::factory()->create();
    foreach ([[$partial, '100.10'], [$partial, '50.20'], [$full, '40.40'], [$unpaid, '10.10']] as [$invoice, $amount]) {
        InvoiceItem::factory()->for($invoice)->create(['amount' => $amount, 'unit_amount' => $amount]);
    }
    foreach ([[$partial, '20.10'], [$partial, '30.20'], [$full, '40.40']] as [$invoice, $amount]) {
        PaymentAllocation::factory()->for($invoice)->create(['amount' => $amount]);
    }
    $void = Payment::factory()->create(['student_id' => $partial->student_id, 'amount' => '5.00', 'voided_at' => now(), 'voided_by_user_id' => $user->id, 'void_reason' => 'Duplicate']);
    PaymentAllocation::factory()->for($partial)->for($void)->create(['amount' => '5.00']);
    Expense::factory()->create(['amount' => '12.34']);
    Expense::factory()->voided()->create(['amount' => '99.99']);
    Expense::factory()->draft()->create(['amount' => '25.00']);

    $totals = FinancialReports::totals('financial-summary', []);
    expect($totals)->toMatchArray(['total' => '200.80', 'paid' => '90.70', 'outstanding' => '110.10', 'income' => '90.70', 'expenses' => '12.34', 'net' => '78.36']);
    expect(FinancialReports::totals('income-expenses', []))->toBe(FinanceSummary::totals());
    expect(FinancialReports::totals('payments', [])['income'])->toBe('90.70');
    expect(FinancialReports::totals('expenses', [])['expenses'])->toBe('12.34');
    expect((new ReportData($user, 'fees', ['status' => 'partially_paid']))->page(10)->pluck('id')->all())->toBe([$partial->id]);
    expect((new ReportData($user, 'fees', ['status' => 'paid']))->page(10)->pluck('id')->all())->toBe([$full->id]);
    expect((new ReportData($user, 'fees', ['status' => 'unpaid']))->page(10)->pluck('id')->all())->toBe([$unpaid->id]);
    expect((new ReportData($user, 'outstanding', ['status' => 'outstanding']))->page(10)->total())->toBe(2);
});

test('academic payment filters sum only matching allocations and expense filters use lifecycle dates and category', function () {
    $user = reportsActor();
    $first = Invoice::factory()->create();
    $second = Invoice::factory()->create(['student_id' => $first->student_id]);
    foreach ([$first, $second] as $invoice) {
        InvoiceItem::factory()->for($invoice)->create();
    }
    $payment = Payment::factory()->create(['student_id' => $first->student_id, 'amount' => '70.00', 'payment_date' => '2026-02-01']);
    PaymentAllocation::factory()->for($first)->for($payment)->create(['amount' => '30.00']);
    PaymentAllocation::factory()->for($second)->for($payment)->create(['amount' => '40.00']);
    $filters = ['academic_year_id' => (string) $first->academic_year_id, 'term_id' => (string) $first->term_id];
    $row = (new ReportData($user, 'payments', $filters))->page(10)->first();
    expect($row->amount)->toBe('70.00');
    expect($row->period_amount)->toBe('30.00');
    expect(FinancialReports::totals('income-expenses', $filters)['income'])->toBe('30.00');
    expect((new ReportData($user, 'payments', ['payment_method' => 'bank_transfer']))->page(10)->total())->toBe(0);
    $expense = Expense::factory()->create(['expense_date' => '2026-02-01', 'amount' => '2.34']);
    Expense::factory()->for($expense->category, 'category')->create(['expense_date' => '2026-01-31']);
    $expenses = new ReportData($user, 'expenses', ['expense_category_id' => (string) $expense->expense_category_id, 'status' => 'recorded', 'date_from' => '2026-02-01', 'date_to' => '2026-02-01']);
    expect($expenses->page(10)->pluck('id')->all())->toBe([$expense->id]);
    expect(FinancialReports::totals('expenses', $expenses->filters)['expenses'])->toBe('2.34');
});

test('invalid report filters fail before SQL and never render misleading results', function (string $report, array $filters, string $field) {
    $user = reportsActor();
    try {
        ReportFilters::validate($user, $report, $filters);
        test()->fail('Expected invalid filters to fail validation.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'year' => ['enrollment', ['academic_year_id' => '999999999'], 'academic_year_id'],
    'class' => ['enrollment', ['class_level_id' => 'bad'], 'class_level_id'],
    'subject' => ['subject-performance', ['subject_id' => '-1'], 'subject_id'],
    'term' => ['financial-summary', ['term_id' => '999999999'], 'term_id'],
    'category' => ['expenses', ['expense_category_id' => 'bad'], 'expense_category_id'],
    'missing category' => ['expenses', ['expense_category_id' => '999999999'], 'expense_category_id'],
    'fee type' => ['fees', ['fee_type_id' => '999999999'], 'fee_type_id'],
    'enrollment status' => ['enrollment', ['status' => 'unknown'], 'status'],
    'expense status' => ['expenses', ['status' => 'paid'], 'status'],
    'payment status' => ['payments', ['status' => 'recorded'], 'status'],
    'status' => ['fees', ['status' => 'cancelled'], 'status'],
    'student attendance status' => ['attendance-students', ['status' => 'unknown'], 'status'],
    'status array' => ['fees', ['status' => ['paid']], 'status'],
    'method' => ['payments', ['payment_method' => 'bitcoin'], 'payment_method'],
    'method array' => ['payments', ['payment_method' => ['cash']], 'payment_method'],
    'date range' => ['expenses', ['date_from' => '2026-02-01', 'date_to' => '2026-01-01'], 'date_to'],
    'invalid date' => ['payments', ['date_from' => '2026-02-30'], 'date_from'],
    'date array' => ['payments', ['date_from' => ['2026-02-01'], 'date_to' => '2026-02-02'], 'date_from'],
    'end date array' => ['payments', ['date_from' => '2026-02-01', 'date_to' => ['2026-02-02']], 'date_to'],
    'array id' => ['financial-summary', ['academic_year_id' => ['1']], 'academic_year_id'],
    'unknown filter' => ['enrollment', ['secret' => '1'], 'filters'],
]);

test('live report validation pagination and reset preserve the intended filter scope', function () {
    $user = reportsActor();
    SchoolSetting::factory()->create(['id' => 1, 'records_per_page' => 10]);
    $first = Enrollment::factory()->create();
    Enrollment::factory()->count(10)->create(['academic_year_id' => $first->academic_year_id, 'class_level_id' => $first->class_level_id]);
    Livewire::actingAs($user)->test('pages::reports.show', ['report' => 'enrollment'])
        ->call('setPage', 2)->assertSet('paginators.page', 2)
        ->set('filters.status', 'active')->call('applyFilters')->assertSet('paginators.page', 1)
        ->set('filters.class_level_id', 'invalid')->call('applyFilters')->assertHasErrors('filters.class_level_id')
        ->assertSee('Select valid filters to generate this report.')
        ->call('resetFilters')->assertHasNoErrors()->assertSet('filters.status', '');
});

test('malformed date ranges return validation feedback on reports and exports', function () {
    $user = reportsActor();
    $filters = ['date_from' => ['2026-02-01'], 'date_to' => '2026-02-02'];

    Livewire::actingAs($user)->test('pages::reports.show', ['report' => 'payments'])
        ->set('filters', $filters)->call('applyFilters')
        ->assertHasErrors('filters.date_from')
        ->assertSee('Select valid filters to generate this report.')
        ->assertDontSee('Download CSV');

    foreach (['csv', 'print'] as $format) {
        $this->actingAs($user)->getJson(route('reports.export', ['report' => 'payments', 'format' => $format, 'filters' => $filters]))
            ->assertUnprocessable()->assertJsonValidationErrors('date_from');
    }
});

test('CSV and print use the same filters totals and safe output as the screen', function () {
    $user = reportsActor();
    SchoolSetting::factory()->create(['id' => 1, 'school_name' => 'Report School', 'currency_code' => 'USD']);
    $expense = Expense::factory()->create(['description' => '=HYPERLINK("evil")<script>alert(1)</script>', 'amount' => '17.25']);
    Expense::factory()->create(['amount' => '999.00']);
    $filters = ['expense_category_id' => (string) $expense->expense_category_id];
    $screen = $this->actingAs($user)->get(route('reports.show', ['report' => 'expenses', 'filters' => $filters]));
    $screen->assertSee('17.25')->assertDontSee('999.00');
    $csv = $this->get(route('reports.export', ['report' => 'expenses', 'format' => 'csv', 'filters' => $filters]));
    $csv->assertDownload('expenses.csv');
    expect($csv->streamedContent())->toContain('Report School', '17.25', "'=HYPERLINK")->not->toContain('999.00');
    $print = $this->get(route('reports.export', ['report' => 'expenses', 'format' => 'print', 'filters' => $filters]));
    expect($print->streamedContent())->toContain('17.25', '&lt;script&gt;')->not->toContain('<script>alert(1)</script>', '999.00');
    $this->getJson(route('reports.export', ['report' => 'expenses', 'format' => 'csv', 'filters' => ['expense_category_id' => 'bad']]))->assertUnprocessable()->assertJsonValidationErrors('expense_category_id');
});

test('report queries remain bounded as the enrollment page grows', function () {
    $user = reportsActor();
    $first = Enrollment::factory()->create();
    Enrollment::factory()->count(24)->create(['academic_year_id' => $first->academic_year_id, 'class_level_id' => $first->class_level_id]);
    $data = new ReportData($user, 'enrollment', []);
    DB::enableQueryLog();
    $page = $data->page(10);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($page->count())->toBe(10);
    expect($page->total())->toBe(25);
    expect($queries)->toBeLessThanOrEqual(2);
    expect(iterator_count($data->rows()))->toBe(25);
});

test('term and assessment filters must match their selected academic context', function () {
    $user = reportsActor();
    $assessment = Assessment::factory()->create();
    $other = Assessment::factory()->create();
    $this->actingAs($user)->getJson(route('reports.export', ['report' => 'financial-summary', 'format' => 'csv', 'filters' => ['academic_year_id' => $other->academic_year_id, 'term_id' => $assessment->term_id]]))
        ->assertUnprocessable()->assertJsonValidationErrors('term_id');
    $this->getJson(route('reports.export', ['report' => 'assessment-results', 'format' => 'csv', 'filters' => ['subject_id' => $other->subject_id, 'assessment_id' => $assessment->id]]))
        ->assertUnprocessable()->assertJsonValidationErrors('assessment_id');
});

test('void invoices remain reportable but cannot inflate billed and outstanding totals', function () {
    $user = reportsActor();
    $invoice = Invoice::factory()->create(['voided_at' => now(), 'voided_by_user_id' => $user->id, 'void_reason' => 'Duplicate']);
    InvoiceItem::factory()->for($invoice)->create(['amount' => '100.00']);
    expect((new ReportData($user, 'fees', ['status' => 'void']))->page(10)->pluck('id')->all())->toBe([$invoice->id]);
    expect(FinancialReports::totals('fees', ['status' => 'void']))->toMatchArray(['records' => '1', 'total' => '0.00', 'paid' => '0.00', 'outstanding' => '0.00']);
    expect(FinancialReports::totals('financial-summary', []))->toMatchArray(['total' => '0.00', 'outstanding' => '0.00']);
    expect((new ReportData($user, 'outstanding', []))->page(10)->total())->toBe(0);
});

test('every report exports valid CSV and print through its shared query pipeline', function (string $report) {
    $user = reportsActor();
    $assessment = Assessment::factory()->create();
    $score = AssessmentScore::factory()->for($assessment)->create();
    $filters = match ($report) {
        'assessment-results' => ['assessment_id' => (string) $assessment->id],
        'class-results' => array_map(strval(...), $assessment->only(['academic_year_id', 'term_id', 'class_level_id'])),
        'attendance-daily' => ['date' => '2026-01-01'],
        'attendance-students' => ['student' => $score->enrollment->student->admission_number],
        default => [],
    };
    $this->actingAs($user);
    foreach (['csv', 'print'] as $format) {
        $response = $this->get(route('reports.export', ['report' => $report, 'format' => $format, 'filters' => $filters]));
        $response->assertOk();
        expect($response->streamedContent())->toContain(ReportCatalog::get($report)['title']);
    }
})->with(array_keys(ReportCatalog::all()));

test('enrollment filter options follow enrollment access rather than results permissions', function () {
    $user = reportsActor([Permissions::REPORTS_VIEW, Permissions::STUDENTS_VIEW]);
    $enrollment = Enrollment::factory()->create();
    $options = ReportFilters::options($user, 'enrollment', []);
    expect($options['class_level_id'])->toHaveKey($enrollment->class_level_id);
});

test('changing dependent filters waits for apply before querying report records', function () {
    $user = reportsActor();
    $enrollment = Enrollment::factory()->create();
    $component = Livewire::actingAs($user)->test('pages::reports.show', ['report' => 'enrollment']);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $component->set('filters.academic_year_id', (string) $enrollment->academic_year_id)
        ->assertSee('Filters have changed. Apply filters to generate the report.')
        ->assertDontSee('Download CSV');
    $reportQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from `enrollments`'));
    DB::disableQueryLog();

    expect($reportQueries)->toBeEmpty();
    $component->call('applyFilters')->assertSee($enrollment->student->admission_number)->assertSee('Download CSV');
});

test('assessment selection can find older records without loading an unbounded or unauthorized list', function () {
    $teacher = reportsActor([Permissions::REPORTS_VIEW, Permissions::RESULTS_VIEW]);
    $older = Assessment::factory()->create(['name' => 'Historical examination']);
    ClassSubject::query()->where('academic_year_id', $older->academic_year_id)->update(['staff_id' => $teacher->id]);
    Assessment::factory()->count(100)->create([...$older->only(['academic_year_id', 'term_id', 'class_level_id', 'subject_id']), 'name' => 'Recent examination']);
    $restricted = Assessment::factory()->create(['name' => 'Restricted examination']);

    expect(ReportFilters::options($teacher, 'assessment-results', [])['assessment_id'])
        ->toHaveCount(100)->not->toHaveKey($older->id);
    expect(ReportFilters::options($teacher, 'assessment-results', [], 'Historical')['assessment_id'])
        ->toBe([$older->id => '#'.$older->id.' '.$older->name]);
    expect(ReportFilters::options($teacher, 'assessment-results', [], (string) $restricted->id)['assessment_id'])->toBeEmpty();
    expect(ReportFilters::describe($teacher, 'assessment-results', ['assessment_id' => (string) $older->id]))
        ->toBe(['Assessment' => '#'.$older->id.' '.$older->name]);

    Livewire::actingAs($teacher)->test('pages::reports.show', ['report' => 'assessment-results'])
        ->set('assessmentSearch', (string) $older->id)->assertSee($older->name)->assertDontSee('Recent examination')
        ->set('filters.assessment_id', (string) $older->id)->call('applyFilters')->assertHasNoErrors()->assertSee('Download CSV')
        ->call('resetFilters')->assertSet('assessmentSearch', '');
});

test('open reports recheck domain permissions when filters are applied', function () {
    $user = reportsActor();
    $component = Livewire::actingAs($user)->test('pages::reports.show', ['report' => 'financial-summary']);
    $user->revokePermissionTo(Permissions::FINANCIAL_REPORTS_VIEW);

    $component->call('applyFilters')->assertForbidden();
});

test('enrollment exports include every filtered record across chunk boundaries', function () {
    $user = reportsActor();
    $first = Enrollment::factory()->create();
    $enrollments = Enrollment::factory()->count(200)->create($first->only(['academic_year_id', 'class_level_id']));
    $enrollments->prepend($first)->load('student');
    $excluded = Enrollment::factory()->create();
    $filters = array_map(strval(...), $first->only(['academic_year_id', 'class_level_id']));

    $response = $this->actingAs($user)->get(route('reports.export', ['report' => 'enrollment', 'format' => 'csv', 'filters' => $filters]));
    $response->assertDownload('enrollment.csv');
    $csvRows = collect(explode("\n", trim($response->streamedContent())))
        ->map(fn (string $line): array => str_getcsv($line, ',', '"', ''));
    $header = $csvRows->search(fn (array $row): bool => ($row[0] ?? '') === 'Admission number');

    expect($header)->not->toBeFalse();
    $admissions = $csvRows->slice($header + 1)->pluck(0)->all();
    expect($admissions)->toBe($enrollments->pluck('student.admission_number')->all())
        ->not->toContain($excluded->student->admission_number);
});

test('fee type filters select whole invoices without multiplying their items or payments', function () {
    $user = reportsActor();
    $invoice = Invoice::factory()->create();
    $item = InvoiceItem::factory()->for($invoice)->create(['amount' => '40.10', 'unit_amount' => '40.10']);
    InvoiceItem::factory()->for($invoice)->create(['amount' => '20.20', 'unit_amount' => '20.20']);
    InvoiceItem::factory()->create(['amount' => '999.00', 'unit_amount' => '999.00']);
    PaymentAllocation::factory()->for($invoice)->create(['amount' => '10.10']);
    PaymentAllocation::factory()->for($invoice)->create(['amount' => '5.20']);
    $filters = ['fee_type_id' => (string) $item->fee_type_id];

    $report = new ReportData($user, 'fees', $filters);

    expect($report->page(10)->pluck('id')->all())->toBe([$invoice->id]);
    expect(FinancialReports::totals('fees', $filters))->toMatchArray([
        'records' => '1', 'total' => '60.30', 'paid' => '15.30', 'outstanding' => '45.00',
    ]);
    $this->actingAs($user)->get(route('reports.show', ['report' => 'fees', 'filters' => $filters]))
        ->assertSee('60.30')->assertSee('15.30')->assertSee('45.00')->assertDontSee('999.00');
    foreach (['csv', 'print'] as $format) {
        $response = $this->get(route('reports.export', ['report' => 'fees', 'format' => $format, 'filters' => $filters]));
        expect($response->streamedContent())->toContain('60.30', '15.30', '45.00')->not->toContain('999.00');
    }
});

test('payment student method date and lifecycle filters exclude nonmatching receipts', function () {
    $user = reportsActor();
    $payment = Payment::factory()->create(['payment_date' => '2026-02-01', 'amount' => '12.34']);
    Payment::factory()->create(['payment_date' => '2026-02-01', 'amount' => '100.00']);
    Payment::factory()->for($payment->student)->create(['payment_date' => '2026-01-31', 'amount' => '200.00']);
    Payment::factory()->for($payment->student)->create(['payment_date' => '2026-02-02', 'amount' => '300.00']);
    Payment::factory()->for($payment->student)->create(['payment_date' => '2026-02-01', 'payment_method' => 'bank_transfer', 'amount' => '400.00']);
    $void = Payment::factory()->for($payment->student)->create([
        'payment_date' => '2026-02-01', 'amount' => '500.00', 'voided_at' => now(),
        'voided_by_user_id' => $user->id, 'void_reason' => 'Duplicate receipt',
    ]);
    $filters = ['student' => $payment->student->admission_number, 'payment_method' => 'cash',
        'date_from' => '2026-02-01', 'date_to' => '2026-02-01', 'status' => 'valid'];

    expect((new ReportData($user, 'payments', $filters))->page(10)->pluck('id')->all())->toBe([$payment->id]);
    expect(FinancialReports::totals('payments', $filters))->toMatchArray(['records' => '1', 'income' => '12.34']);
    $voidFilters = [...$filters, 'status' => 'void'];
    expect((new ReportData($user, 'payments', $voidFilters))->page(10)->pluck('id')->all())->toBe([$void->id]);
    expect(FinancialReports::totals('payments', $voidFilters))->toMatchArray(['records' => '1', 'income' => '0.00']);
});
