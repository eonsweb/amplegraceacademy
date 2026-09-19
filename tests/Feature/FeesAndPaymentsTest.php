<?php

use App\Actions\Fees\GenerateInvoices;
use App\Actions\Fees\RecordPayment;
use App\Actions\Fees\SaveFeeStructures;
use App\Actions\Fees\VoidInvoice;
use App\Actions\Fees\VoidPayment;
use App\EnrollmentStatus;
use App\InvoiceStatus;
use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\Enrollment;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Fees\FeeLedger;
use App\Support\Fees\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/** @param list<string> $permissions */
function feeActor(array $permissions = []): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

/** @return array{AcademicYear, Term, ClassLevel, FeeType} */
function feeContext(): array
{
    $year = AcademicYear::factory()->create();

    return [$year, Term::factory()->for($year)->create(), ClassLevel::factory()->create(), FeeType::factory()->create(['name' => 'Tuition'])];
}

function billedInvoice(string $amount = '100.00'): Invoice
{
    $invoice = Invoice::factory()->create();
    InvoiceItem::factory()->for($invoice)->create(['unit_amount' => $amount, 'amount' => $amount]);

    return $invoice;
}

/** @return array<string, mixed> */
function feePaymentData(Invoice $invoice, string $amount = '100.00'): array
{
    return ['student_id' => $invoice->student_id, 'invoice_id' => $invoice->id, 'amount' => $amount, 'payment_date' => today()->toDateString(), 'payment_method' => 'cash', 'submission_key' => (string) Str::uuid()];
}

test('fee structure bulk save creates updates and audits a configuration', function () {
    $actor = feeActor([Permissions::FEES_MANAGE]);
    [$year, $term, $class, $type] = feeContext();
    $rows = [['class_level_id' => $class->id, 'fee_type_id' => $type->id, 'amount' => '1200.25', 'is_active' => true]];

    app(SaveFeeStructures::class)->handle($actor, $year->id, $term->id, $rows);

    $this->assertDatabaseHas('fee_structures', ['term_id' => $term->id, 'amount' => '1200.25', 'updated_by_user_id' => $actor->id]);
    $rows[0]['amount'] = '1300.50';
    $rows[0]['is_active'] = false;
    app(SaveFeeStructures::class)->handle($actor, $year->id, $term->id, $rows);
    $this->assertDatabaseCount('fee_structures', 1);
    $this->assertDatabaseHas('fee_structures', ['amount' => '1300.50', 'is_active' => false]);
    $audit = DB::table('financial_audits')->orderByDesc('id')->first();
    expect(json_decode($audit->changes, true))->toBe(['before' => ['amount' => '1200.25', 'is_active' => true], 'after' => ['amount' => '1300.50', 'is_active' => false]]);
});

test('fee structures reject invalid monetary amounts', function (string $amount) {
    $actor = feeActor([Permissions::FEES_MANAGE]);
    [$year, $term, $class, $type] = feeContext();

    expect(fn () => app(SaveFeeStructures::class)->handle($actor, $year->id, $term->id, [['class_level_id' => $class->id, 'fee_type_id' => $type->id, 'amount' => $amount, 'is_active' => true]]))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('fee_structures', 0);
    $this->assertDatabaseCount('financial_audits', 0);
})->with(['zero' => '0', 'negative' => '-1', 'precision' => '10.001', 'overflow' => '10000000000', 'exponent' => '1e2', 'text' => 'money']);

test('bulk input rejects duplicate fee context before writing', function () {
    $actor = feeActor([Permissions::FEES_MANAGE]);
    [$year, $term, $class, $type] = feeContext();
    $row = ['class_level_id' => $class->id, 'fee_type_id' => $type->id, 'amount' => '100.00', 'is_active' => true];

    expect(fn () => app(SaveFeeStructures::class)->handle($actor, $year->id, $term->id, [$row, $row]))->toThrow(ValidationException::class, 'Duplicate class and fee type.');

    $this->assertDatabaseCount('fee_structures', 0);
});

test('database prevents duplicate fee structures', function () {
    $structure = FeeStructure::factory()->create();

    expect(fn () => FeeStructure::factory()->create($structure->only(['academic_year_id', 'term_id', 'class_level_id', 'fee_type_id'])))->toThrow(QueryException::class);
    $this->assertDatabaseCount('fee_structures', 1);
});

test('fee configuration rejects mismatched years and unknown contexts', function () {
    $actor = feeActor([Permissions::FEES_MANAGE]);
    [$year, $term, $class, $type] = feeContext();
    $row = ['class_level_id' => $class->id, 'fee_type_id' => $type->id, 'amount' => '100.00', 'is_active' => true];

    expect(fn () => app(SaveFeeStructures::class)->handle($actor, AcademicYear::factory()->create()->id, $term->id, [$row]))->toThrow(ValidationException::class);
    $row['class_level_id'] = 999999;
    expect(fn () => app(SaveFeeStructures::class)->handle($actor, $year->id, $term->id, [$row]))->toThrow(ValidationException::class, 'Select an existing class and fee type.');
    $this->assertDatabaseCount('fee_structures', 0);
});

test('financial write actions refuse actors without their permissions', function () {
    $actor = feeActor();
    [$year, $term, $class] = feeContext();
    $invoice = billedInvoice();
    $payment = Payment::factory()->create();

    expect(fn () => app(SaveFeeStructures::class)->handle($actor, $year->id, $term->id, []))->toThrow(AuthorizationException::class);
    expect(fn () => app(GenerateInvoices::class)->handle($actor, $year->id, $term->id, $class->id, today()->toDateString()))->toThrow(AuthorizationException::class);
    expect(fn () => app(RecordPayment::class)->handle($actor, feePaymentData($invoice)))->toThrow(AuthorizationException::class);
    expect(fn () => app(VoidPayment::class)->handle($actor, $payment->id, 'Incorrect entry'))->toThrow(AuthorizationException::class);
    expect(fn () => app(VoidInvoice::class)->handle($actor, $invoice->id, 'Incorrect entry'))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('financial_audits', 0);
});

test('invoice generation snapshots charges and context and skips duplicates', function () {
    $actor = feeActor([Permissions::INVOICES_GENERATE]);
    [$year, $term, $class, $type] = feeContext();
    FeeStructure::factory()->for($year)->for($term)->for($class)->for($type)->create(['amount' => '1200.25']);
    $enrollments = Enrollment::factory()->count(3)->for($year)->for($class)->create();

    $count = app(GenerateInvoices::class)->handle($actor, $year->id, $term->id, $class->id, today()->toDateString());

    expect($count)->toBe(3);
    foreach ($enrollments as $enrollment) {
        $invoice = FeeLedger::invoices()->where('enrollment_id', $enrollment->id)->firstOrFail();
        expect($invoice->total)->toBe('1200.25');
        expect($invoice->class_name)->toBe($class->name);
        expect($invoice->student_name)->toBe($enrollment->student->fullName());
        expect($invoice->status())->toBe(InvoiceStatus::Unpaid);
    }
    $this->assertDatabaseHas('invoice_items', ['description' => 'Tuition', 'amount' => '1200.25', 'quantity' => 1]);
    expect(app(GenerateInvoices::class)->handle($actor, $year->id, $term->id, $class->id, today()->toDateString()))->toBe(0);
    $this->assertDatabaseCount('invoices', 3);
    $this->assertDatabaseCount('financial_audits', 3);
});

test('later fee renaming class promotion and price changes preserve historic invoices', function () {
    $actor = feeActor([Permissions::INVOICES_GENERATE]);
    [$year, $term, $class, $type] = feeContext();
    $structure = FeeStructure::factory()->for($year)->for($term)->for($class)->for($type)->create(['amount' => '1200.00']);
    $enrollment = Enrollment::factory()->for($year)->for($class)->create();
    app(GenerateInvoices::class)->handle($actor, $year->id, $term->id, $class->id, today()->toDateString());
    $originalClass = $class->name;

    $structure->update(['amount' => '1800.00']);
    $type->update(['name' => 'New fee name']);
    $class->update(['name' => 'Renamed class']);
    $enrollment->update(['status' => EnrollmentStatus::Promoted]);
    Enrollment::factory()->for($enrollment->student)->create();

    $invoice = FeeLedger::invoices()->firstOrFail();
    expect($invoice->total)->toBe('1200.00');
    expect($invoice->class_name)->toBe($originalClass);
    expect($invoice->enrollment_id)->toBe($enrollment->id);
    expect($invoice->items->first()->description)->toBe('Tuition');
});

test('different terms generate independent invoices for the same enrollment', function () {
    $actor = feeActor([Permissions::INVOICES_GENERATE]);
    [$year, $term, $class, $type] = feeContext();
    $second = Term::factory()->second()->for($year)->create();
    Enrollment::factory()->for($year)->for($class)->create();
    foreach ([$term, $second] as $period) {
        FeeStructure::factory()->for($year)->for($period)->for($class)->for($type)->create();
        app(GenerateInvoices::class)->handle($actor, $year->id, $period->id, $class->id, today()->toDateString());
    }
    $this->assertDatabaseCount('invoices', 2);
    expect(Money::minor(FeeLedger::summary(FeeLedger::invoices())['outstanding']))->toBe(20000);
});

test('allocation factory creates a billed invoice and a matching payment', function () {
    $allocation = PaymentAllocation::factory()->create(['amount' => '75.25']);

    expect($allocation->payment->student_id)->toBe($allocation->invoice->student_id);
    expect($allocation->payment->amount)->toBe('75.25');
    $balance = FeeLedger::invoices()->findOrFail($allocation->invoice_id);
    expect($balance->total)->toBe('75.25');
    expect($balance->outstanding)->toBe('0.00');
});

test('inactive fees and inactive enrollments are excluded from billing', function () {
    $actor = feeActor([Permissions::INVOICES_GENERATE]);
    [$year, $term, $class, $type] = feeContext();
    FeeStructure::factory()->for($year)->for($term)->for($class)->for($type)->create();
    FeeStructure::factory()->for($year)->for($term)->for($class)->create(['is_active' => false]);
    $inactiveType = FeeType::factory()->create(['is_active' => false]);
    FeeStructure::factory()->for($year)->for($term)->for($class)->for($inactiveType)->create();
    Enrollment::factory()->for($year)->for($class)->create();
    Enrollment::factory()->for($year)->for($class)->create(['status' => EnrollmentStatus::Promoted]);

    expect(app(GenerateInvoices::class)->handle($actor, $year->id, $term->id, $class->id, today()->toDateString()))->toBe(1);
    $this->assertDatabaseCount('invoice_items', 1);
});

test('invoice generation requires fees and a fresh preview', function () {
    $actor = feeActor([Permissions::INVOICES_GENERATE]);
    [$year, $term, $class, $type] = feeContext();
    Enrollment::factory()->for($year)->for($class)->create();

    expect(fn () => app(GenerateInvoices::class)->handle($actor, $year->id, $term->id, $class->id, today()->toDateString()))->toThrow(ValidationException::class, 'Configure active fee items');
    $structure = FeeStructure::factory()->for($year)->for($term)->for($class)->for($type)->create();
    $preview = app(GenerateInvoices::class)->preview($actor, $year->id, $term->id, $class->id);
    $structure->update(['amount' => '200.00']);
    expect(fn () => app(GenerateInvoices::class)->handle($actor, $year->id, $term->id, $class->id, today()->toDateString(), fingerprint: $preview['fingerprint']))->toThrow(ValidationException::class, 'Fees changed since preview');
    $this->assertDatabaseCount('invoices', 0);
});

test('payments support full partial and multiple settlements', function (array $amounts, string $paid, string $outstanding, InvoiceStatus $status) {
    $actor = feeActor([Permissions::PAYMENTS_RECORD]);
    $invoice = billedInvoice('100.25');

    foreach ($amounts as $amount) {
        app(RecordPayment::class)->handle($actor, feePaymentData($invoice, $amount));
    }

    $balance = FeeLedger::invoices()->findOrFail($invoice->id);
    expect($balance->paid)->toBe($paid);
    expect($balance->outstanding)->toBe($outstanding);
    expect($balance->status())->toBe($status);
    $this->assertDatabaseCount('payments', count($amounts));
    $this->assertDatabaseCount('payment_allocations', count($amounts));
    $this->assertDatabaseHas('payments', ['received_by_user_id' => $actor->id, 'received_by_name' => $actor->name]);
})->with([
    'full' => [['100.25'], '100.25', '0.00', InvoiceStatus::Paid],
    'partial' => [['40.10'], '40.10', '60.15', InvoiceStatus::PartiallyPaid],
    'multiple' => [['30.10', '20.05', '50.10'], '100.25', '0.00', InvoiceStatus::Paid],
]);

test('invalid payments leave no financial records', function (string $amount) {
    $actor = feeActor([Permissions::PAYMENTS_RECORD]);
    $invoice = billedInvoice();

    expect(fn () => app(RecordPayment::class)->handle($actor, feePaymentData($invoice, $amount)))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('payment_allocations', 0);
})->with(['overpayment' => '100.01', 'zero' => '0', 'negative' => '-10', 'precision' => '1.001', 'exponent' => '1e1', 'overflow' => '10000000000']);

test('payments validate invoice ownership method and dates', function () {
    $actor = feeActor([Permissions::PAYMENTS_RECORD]);
    $invoice = billedInvoice();
    foreach ([
        ['student_id' => Student::factory()->create()->id],
        ['payment_method' => 'invented'],
        ['payment_date' => today()->addDay()->toDateString()],
        ['payment_date' => today()->subDay()->toDateString()],
        ['invoice_id' => 999999],
    ] as $invalid) {
        expect(fn () => app(RecordPayment::class)->handle($actor, array_replace(feePaymentData($invoice), $invalid)))->toThrow(ValidationException::class);
    }
    $this->assertDatabaseCount('payments', 0);
});

test('payment recording rejects floating point input instead of truncating money', function () {
    $actor = feeActor([Permissions::PAYMENTS_RECORD]);
    $invoice = billedInvoice();
    $data = array_replace(feePaymentData($invoice), ['amount' => 25.75]);

    expect(fn () => app(RecordPayment::class)->handle($actor, $data))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('payments', 0);
    expect(FeeLedger::invoices()->findOrFail($invoice->id)->outstanding)->toBe('100.00');
});

test('repeated payment submissions return the original receipt without charging twice', function () {
    $actor = feeActor([Permissions::PAYMENTS_RECORD]);
    $invoice = billedInvoice();
    $data = feePaymentData($invoice, '25.00');
    $first = app(RecordPayment::class)->handle($actor, $data);

    $second = app(RecordPayment::class)->handle($actor, $data);

    expect($second->id)->toBe($first->id);
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('payment_allocations', 1);
    expect(FeeLedger::invoices()->findOrFail($invoice->id)->outstanding)->toBe('75.00');
    expect(fn () => app(RecordPayment::class)->handle($actor, array_replace($data, ['amount' => '30.00'])))->toThrow(ValidationException::class, 'This submission was already used.');
});

test('database rejects duplicate receipt numbers', function () {
    $payment = Payment::factory()->create();

    expect(fn () => Payment::factory()->create(['receipt_number' => $payment->receipt_number]))->toThrow(QueryException::class);
    $this->assertDatabaseCount('payments', 1);
});

test('voiding a payment retains its history restores balances and is idempotent', function () {
    $actor = feeActor([Permissions::PAYMENTS_RECORD, Permissions::PAYMENTS_VOID]);
    $invoice = billedInvoice();
    $payment = app(RecordPayment::class)->handle($actor, feePaymentData($invoice));

    app(VoidPayment::class)->handle($actor, $payment->id, 'Incorrect payment entry');
    app(VoidPayment::class)->handle($actor, $payment->id, 'Second attempt');

    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'voided_by_user_id' => $actor->id, 'void_reason' => 'Incorrect payment entry']);
    $this->assertDatabaseCount('payment_allocations', 1);
    $balance = FeeLedger::invoices()->findOrFail($invoice->id);
    expect($balance->paid)->toBe('0.00');
    expect($balance->outstanding)->toBe('100.00');
    expect($balance->status())->toBe(InvoiceStatus::Unpaid);
    expect(DB::table('financial_audits')->where('action', 'payment.voided')->count())->toBe(1);
});

test('invoices with valid payments cannot be voided until payment is voided', function () {
    $actor = feeActor([Permissions::PAYMENTS_RECORD, Permissions::PAYMENTS_VOID, Permissions::INVOICES_VOID]);
    $invoice = billedInvoice();
    $payment = app(RecordPayment::class)->handle($actor, feePaymentData($invoice, '25.00'));

    expect(fn () => app(VoidInvoice::class)->handle($actor, $invoice->id, 'Incorrect invoice'))->toThrow(ValidationException::class, 'Void the payments before voiding this invoice.');
    app(VoidPayment::class)->handle($actor, $payment->id, 'Incorrect payment');
    app(VoidInvoice::class)->handle($actor, $invoice->id, 'Incorrect invoice');

    expect(FeeLedger::invoices()->findOrFail($invoice->id)->status())->toBe(InvoiceStatus::Void);
    expect(Money::minor(FeeLedger::summary(FeeLedger::invoices())['outstanding']))->toBe(0);
    expect(fn () => app(RecordPayment::class)->handle($actor, feePaymentData($invoice)))->toThrow(ValidationException::class, 'A void invoice cannot receive payments.');
    $this->assertModelExists($invoice);
});

test('financial records cannot be silently edited or deleted', function () {
    $invoice = billedInvoice();
    $actor = feeActor([Permissions::PAYMENTS_RECORD]);
    $payment = app(RecordPayment::class)->handle($actor, feePaymentData($invoice));
    foreach ([$invoice, $invoice->items->first(), $payment, $payment->allocations->first()] as $record) {
        expect(fn () => $record->delete())->toThrow(ValidationException::class, 'Financial history cannot be deleted.');
    }
    expect(fn () => $payment->forceFill(['amount' => '1.00'])->save())->toThrow(ValidationException::class);
    expect(fn () => $invoice->items->first()->forceFill(['amount' => '1.00'])->save())->toThrow(ValidationException::class);
    expect(fn () => $invoice->forceFill(['class_name' => 'Wrong class'])->save())->toThrow(ValidationException::class);
    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'amount' => '100.00']);
});

test('financial parent deletion is restricted', function () {
    $invoice = billedInvoice();

    expect(fn () => DB::table('students')->where('id', $invoice->student_id)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('enrollments')->where('id', $invoice->enrollment_id)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('fee_types')->where('id', $invoice->items->first()->fee_type_id)->delete())->toThrow(QueryException::class);
});

test('invoice generation query count stays bounded for a class', function (int $students) {
    $actor = feeActor([Permissions::INVOICES_GENERATE]);
    [$year, $term, $class, $type] = feeContext();
    FeeStructure::factory()->for($year)->for($term)->for($class)->for($type)->create();
    Enrollment::factory()->count($students)->for($year)->for($class)->create();
    $actor->can(Permissions::INVOICES_GENERATE);
    DB::enableQueryLog();
    DB::flushQueryLog();

    app(GenerateInvoices::class)->handle($actor, $year->id, $term->id, $class->id, today()->toDateString());

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($count)->toBeLessThanOrEqual(20);
    $this->assertDatabaseCount('invoices', $students);
})->with([2, 35]);
