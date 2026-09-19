<?php

use App\Actions\Fees\RecordPayment;
use App\Actions\Fees\VoidPayment;
use App\Models\Enrollment;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Authorization\Roles;
use App\Support\Fees\Money;
use App\Support\Settings\SystemSettings;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/** @param list<string>|null $permissions */
function financialPageActor(?array $permissions = null): User
{
    $permissions ??= [Permissions::FEES_VIEW, Permissions::FEES_MANAGE, Permissions::FEE_TYPES_MANAGE, Permissions::INVOICES_VIEW, Permissions::INVOICES_GENERATE, Permissions::INVOICES_VOID, Permissions::PAYMENTS_RECORD, Permissions::PAYMENTS_VIEW, Permissions::PAYMENTS_VOID, Permissions::BALANCES_VIEW, Permissions::FINANCIAL_REPORTS_VIEW, Permissions::RECEIPTS_PRINT];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function financialPagePayment(User $actor, Invoice $invoice, string $amount = '25.00'): Payment
{
    return app(RecordPayment::class)->handle($actor, ['student_id' => $invoice->student_id, 'invoice_id' => $invoice->id, 'amount' => $amount, 'payment_date' => today()->toDateString(), 'payment_method' => 'cash', 'submission_key' => (string) Str::uuid()]);
}

test('financial routes require authentication and specific permissions', function (string $route) {
    $this->get(route($route))->assertRedirect(route('home'));
    $this->actingAs(financialPageActor([]))->get(route($route))->assertForbidden();
    $this->actingAs(financialPageActor())->get(route($route))->assertSuccessful();
})->with(['fees.overview', 'fees.types', 'fees.structures', 'fees.invoices', 'fees.record-payment', 'fees.payments', 'fees.outstanding']);

test('fee types support create edit deactivation and safe deletion', function () {
    $this->actingAs(financialPageActor());
    $page = Livewire::test('pages::fees.types')->call('create')->set('name', 'Tuition')->set('code', ' tuition ')->call('save')->assertHasNoErrors();
    $type = FeeType::query()->sole();
    $this->assertDatabaseHas('fee_types', ['name' => 'Tuition', 'code' => 'TUITION']);

    $page->call('edit', $type->id)->set('isActive', false)->call('save')->assertHasNoErrors();
    $this->assertDatabaseHas('fee_types', ['id' => $type->id, 'is_active' => false]);
    FeeStructure::factory()->for($type)->create();
    $page->call('delete', $type->id)->assertHasErrors('delete');
    $this->assertModelExists($type);
    $unused = FeeType::factory()->create();
    $page->call('delete', $unused->id);
    $this->assertModelMissing($unused);
});

test('fee type validation rejects empty names invalid codes and duplicate codes', function () {
    $this->actingAs(financialPageActor());
    $type = FeeType::factory()->create(['code' => 'TUITION']);
    $page = Livewire::test('pages::fees.types')->call('create')->call('save')->assertHasErrors(['name', 'code']);
    $page->set('name', 'Other tuition')->set('code', $type->code)->call('save')->assertHasErrors('code');
    $page->set('code', 'BAD CODE')->call('save')->assertHasErrors('code');
    $this->assertDatabaseCount('fee_types', 1);
});

test('fee structure grid loads and saves only the selected academic context', function () {
    $this->actingAs(financialPageActor());
    $structure = FeeStructure::factory()->create();
    $page = Livewire::test('pages::fees.structures')
        ->set('academicYearId', (string) $structure->academic_year_id)
        ->set('termId', (string) $structure->term_id)
        ->call('loadGrid')->assertHasNoErrors()
        ->set("amounts.{$structure->fee_type_id}.{$structure->class_level_id}", '145.75')
        ->call('save')->assertHasNoErrors();

    $this->assertDatabaseHas('fee_structures', ['id' => $structure->id, 'amount' => '145.75']);
    $page->set('termId', '')->call('save')->assertHasErrors('rows');
});

test('invoice generation UI requires preview and shows created invoices', function () {
    $this->actingAs(financialPageActor());
    $structure = FeeStructure::factory()->create();
    $enrollment = Enrollment::factory()->create(['academic_year_id' => $structure->academic_year_id, 'class_level_id' => $structure->class_level_id]);

    $page = Livewire::test('pages::fees.invoices')
        ->set('academicYearId', (string) $structure->academic_year_id)->set('termId', (string) $structure->term_id)->set('classLevelId', (string) $structure->class_level_id)
        ->call('generate')->assertHasErrors('fees')
        ->call('previewInvoices')->assertSet('preview.students', 1)
        ->call('generate')->assertSee($enrollment->student->admission_number);
    $this->assertDatabaseCount('invoices', 1);
    $page->call('previewInvoices')->assertSet('preview.students', 0);
});

test('payment UI searches admission numbers prioritizes exact matches and records once', function () {
    $actor = financialPageActor();
    $this->actingAs($actor);
    $invoice = InvoiceItem::factory()->create()->invoice;
    Student::factory()->create(['admission_number' => $invoice->admission_number.'-OTHER']);
    $page = Livewire::test('pages::fees.record-payment')->set('search', $invoice->admission_number);
    expect($page->get('students')->first()->id)->toBe($invoice->student_id);

    $page->call('selectStudent', $invoice->student_id)->assertSee($invoice->invoice_number)
        ->set('invoiceId', (string) $invoice->id)->set('amount', '40.00')->call('save')->assertHasNoErrors()->assertSee('Payment saved')
        ->call('save');
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseHas('payment_allocations', ['invoice_id' => $invoice->id, 'amount' => '40.00']);
    $page->call('newPayment')->set('invoiceId', (string) $invoice->id)->set('amount', '70.00')->call('save')->assertHasErrors('amount');
    $this->assertDatabaseCount('payments', 1);
});

test('financial details and receipts enforce their own permissions', function () {
    $invoice = InvoiceItem::factory()->create()->invoice;
    $actor = financialPageActor();
    $payment = financialPagePayment($actor, $invoice);
    foreach ([['fees.invoice', $invoice], ['fees.account', $invoice->student_id], ['fees.receipt', $payment]] as [$route, $record]) {
        $this->actingAs(financialPageActor([]))->get(route($route, $record))->assertForbidden();
        $this->actingAs($actor)->get(route($route, $record))->assertSuccessful();
    }
    expect(Gate::forUser($actor)->allows('print', $payment))->toBeTrue();
    expect(Gate::forUser(financialPageActor([Permissions::PAYMENTS_VIEW]))->allows('print', $payment))->toBeFalse();
});

test('receipt preserves invoice context receiver and configured currency', function () {
    $actor = financialPageActor();
    $invoice = InvoiceItem::factory()->create()->invoice;
    $payment = financialPagePayment($actor, $invoice);
    $originalReceiver = $actor->name;
    $actor->update(['name' => 'Renamed receiver']);
    $invoice->student->update(['first_name' => 'Renamed student']);
    app(SystemSettings::class)->update(['currency_code' => 'USD', 'school_name' => 'Receipt School']);

    $this->actingAs($actor)->get(route('fees.receipt', $payment))
        ->assertSuccessful()
        ->assertSee('Receipt School')->assertSee($payment->receipt_number)
        ->assertSee($invoice->student_name)->assertSee($invoice->class_name)
        ->assertSee($invoice->academic_year_name)->assertSee($invoice->term_name)
        ->assertSee($originalReceiver)->assertSee('$25.00')->assertSee('$75.00');
});

test('payment history finds full student names including middle names', function () {
    $actor = financialPageActor();
    $invoice = InvoiceItem::factory()->create()->invoice;
    $invoice->student->update(['first_name' => 'Ama', 'middle_name' => 'Akua', 'last_name' => 'Mensah']);
    $payment = financialPagePayment($actor, $invoice);
    $this->actingAs($actor);

    Livewire::test('pages::fees.payments')->set('search', 'Ama Akua Mensah')
        ->assertSee($payment->receipt_number);
});

test('student account totals include history and filter by year and term', function () {
    $actor = financialPageActor();
    $this->actingAs($actor);
    $first = InvoiceItem::factory()->create()->invoice;
    $secondTerm = Term::factory()->second()->create(['academic_year_id' => $first->academic_year_id]);
    $second = Invoice::factory()->create(['enrollment_id' => $first->enrollment_id, 'term_id' => $secondTerm->id]);
    InvoiceItem::factory()->for($second)->create(['amount' => '200.00', 'unit_amount' => '200.00']);
    $third = Invoice::factory()->create(['enrollment_id' => Enrollment::factory()->create(['student_id' => $first->student_id])->id]);
    InvoiceItem::factory()->for($third)->create(['amount' => '300.00', 'unit_amount' => '300.00']);
    financialPagePayment($actor, $first);

    $page = Livewire::test('pages::fees.account', ['student' => $first->student])
        ->assertSee($first->invoice_number)->assertSee($second->invoice_number)->assertSee($third->invoice_number);
    expect(Money::minor($page->get('totals')['total']))->toBe(60000);
    expect(Money::minor($page->get('totals')['paid']))->toBe(2500);
    expect(Money::minor($page->get('totals')['outstanding']))->toBe(57500);
    $page->set('academicYearId', (string) $first->academic_year_id)->assertDontSee($third->invoice_number);
    expect(Money::minor($page->get('totals')['total']))->toBe(30000);
    $page->set('termId', (string) $secondTerm->id)->assertDontSee($first->invoice_number)->assertSee($second->invoice_number);
    expect(Money::minor($page->get('totals')['outstanding']))->toBe(20000);
});

test('payment history filters receipts methods dates and period and supports audited voiding', function () {
    $actor = financialPageActor();
    $this->actingAs($actor);
    $invoice = InvoiceItem::factory()->create()->invoice;
    $payment = financialPagePayment($actor, $invoice);
    $otherInvoice = InvoiceItem::factory()->create()->invoice;
    $other = financialPagePayment($actor, $otherInvoice);

    $page = Livewire::test('pages::fees.payments')->assertSee($payment->receipt_number)->assertSee($other->receipt_number)
        ->set('academicYearId', (string) $invoice->academic_year_id)->set('termId', (string) $invoice->term_id)
        ->assertSee($payment->receipt_number)->assertDontSee($other->receipt_number)
        ->set('method', 'card')->assertDontSee($payment->receipt_number)->set('method', 'cash')
        ->set('dateFrom', today()->addDay()->toDateString())->assertDontSee($payment->receipt_number)->set('dateFrom', '')
        ->set('search', $payment->receipt_number)->assertSee($payment->receipt_number)
        ->call('openVoid', $payment->id)->set('reason', 'Incorrect receipt entry')->call('voidPayment')->assertHasNoErrors()->assertSee('Incorrect receipt entry');
    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'voided_by_user_id' => $actor->id]);
});

test('outstanding page filters class student and paid invoices', function () {
    $actor = financialPageActor();
    $this->actingAs($actor);
    $invoice = InvoiceItem::factory()->create()->invoice;
    $paid = InvoiceItem::factory()->create()->invoice;
    financialPagePayment($actor, $paid, '100.00');

    Livewire::test('pages::fees.outstanding')->assertSee($invoice->invoice_number)->assertDontSee($paid->invoice_number)
        ->set('classLevelId', (string) $invoice->class_level_id)->assertSee($invoice->invoice_number)
        ->set('search', 'no matching student')->assertDontSee($invoice->invoice_number)
        ->set('search', $invoice->admission_number)->assertSee($invoice->invoice_number);
});

test('fees overview excludes void payments and filters aggregate totals by period', function () {
    $actor = financialPageActor();
    $this->actingAs($actor);
    $invoice = InvoiceItem::factory()->create()->invoice;
    financialPagePayment($actor, $invoice, '30.25');
    $voided = financialPagePayment($actor, $invoice, '20.00');
    app(VoidPayment::class)->handle($actor, $voided->id, 'Incorrect payment');
    $other = InvoiceItem::factory()->create()->invoice;
    financialPagePayment($actor, $other, '40.00');

    $page = Livewire::test('pages::fees.overview');
    expect(Money::minor($page->get('totals')['total']))->toBe(20000);
    expect(Money::minor($page->get('totals')['paid']))->toBe(7025);
    expect($page->get('totals')['owing'])->toBe(2);
    $page->set('academicYearId', (string) $invoice->academic_year_id)->set('termId', (string) $invoice->term_id);
    expect(Money::minor($page->get('totals')['outstanding']))->toBe(6975);
    expect($page->get('totals')['owing'])->toBe(1);
});

test('payment and invoice void actions cannot be invoked by a read only user', function () {
    $actor = financialPageActor();
    $invoice = InvoiceItem::factory()->create()->invoice;
    $payment = financialPagePayment($actor, $invoice);
    $this->actingAs(financialPageActor([Permissions::PAYMENTS_VIEW, Permissions::INVOICES_VIEW]));

    Livewire::test('pages::fees.payments')->call('openVoid', $payment->id)->assertForbidden();
    Livewire::test('pages::fees.invoice', ['invoice' => $invoice])->set('reason', 'Incorrect invoice')->call('voidInvoice')->assertForbidden();
    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'voided_at' => null]);
    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'voided_at' => null]);
});

test('seeded roles keep financial management restricted and navigation permission aware', function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class]);
    foreach ([Roles::TEACHER, Roles::HEADMASTER] as $role) {
        $user = User::factory()->create()->assignRole($role);
        expect($user->can(Permissions::PAYMENTS_RECORD))->toBeFalse();
        $this->actingAs($user)->get(route('dashboard'))->assertDontSee('Fees &amp; Payments', false);
    }
    $owner = User::factory()->create()->assignRole(Roles::PROPRIETOR);
    expect($owner->can(Permissions::PAYMENTS_RECORD))->toBeFalse();
    expect($owner->can(Permissions::BALANCES_VIEW))->toBeTrue();
    $admin = User::factory()->create()->assignRole(Roles::ADMIN);
    expect($admin->can(Permissions::PAYMENTS_VOID))->toBeTrue();
    $this->actingAs($admin)->get(route('fees.index'))->assertRedirect(route('fees.overview'));
});

test('financial read pages do not amplify queries as rows grow', function (string $pageName) {
    $actor = financialPageActor();
    $this->actingAs($actor);
    $first = InvoiceItem::factory()->create()->invoice;
    financialPagePayment($actor, $first);
    $parameters = $pageName === 'account' ? ['student' => $first->student] : [];
    Livewire::test('pages::fees.'.$pageName, $parameters);
    DB::enableQueryLog();
    DB::flushQueryLog();
    Livewire::test('pages::fees.'.$pageName, $parameters);
    $smallCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    for ($index = 0; $index < 12; $index++) {
        $enrollment = Enrollment::factory()->create(['student_id' => $first->student_id]);
        $invoice = Invoice::factory()->create(['enrollment_id' => $enrollment->id]);
        InvoiceItem::factory()->for($invoice)->create();
        financialPagePayment($actor, $invoice);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();

    Livewire::test('pages::fees.'.$pageName, $parameters);

    $largeCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($largeCount)->toBeLessThanOrEqual($smallCount + 2);
})->with(['outstanding', 'account', 'payments']);
