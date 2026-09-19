<?php

use App\Actions\Expenses\ManageExpenseCategory;
use App\Actions\Expenses\SaveExpense;
use App\Actions\Expenses\VoidExpense;
use App\Actions\Fees\RecordPayment;
use App\Actions\Fees\VoidPayment;
use App\ExpenseStatus;
use App\Models\AcademicYear;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Term;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Fees\Money;
use App\Support\Finance\FinanceSummary;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** @param list<string>|null $permissions */
function expenseActor(?array $permissions = null): User
{
    $permissions ??= [Permissions::EXPENSES_VIEW, Permissions::EXPENSES_CREATE, Permissions::EXPENSES_UPDATE,
        Permissions::EXPENSES_DELETE, Permissions::EXPENSES_VOID, Permissions::EXPENSE_CATEGORIES_MANAGE,
        Permissions::FINANCIAL_REPORTS_VIEW, Permissions::PAYMENTS_RECORD, Permissions::PAYMENTS_VOID];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    return $actor;
}

/** @return array<string, mixed> */
function expenseInput(?ExpenseCategory $category = null): array
{
    return ['expense_category_id' => ($category ?? ExpenseCategory::factory()->create())->id,
        'amount' => '120.35', 'expense_date' => today()->toDateString(), 'description' => 'Books for library',
        'payment_method' => 'cash', 'submission_key' => (string) Str::uuid()];
}

test('recording an expense stores exact money and server assigned recorder and audit', function () {
    $actor = expenseActor();
    $term = Term::factory()->create();
    $input = [...expenseInput(), 'academic_year_id' => $term->academic_year_id, 'term_id' => $term->id,
        'recorded_by_user_id' => User::factory()->create()->id, 'status' => 'voided', 'amount' => '9999999999.99'];

    $expense = app(SaveExpense::class)->handle($actor, $input);

    $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'amount' => '9999999999.99',
        'status' => 'recorded', 'recorded_by_user_id' => $actor->id, 'term_id' => $term->id]);
    expect($expense->recorded_at)->not->toBeNull();
    expect($expense->category->id)->toBe($input['expense_category_id']);
    expect($expense->academicYear->id)->toBe($term->academic_year_id);
    expect($expense->term->id)->toBe($term->id);
    expect($expense->recordedBy->id)->toBe($actor->id);
    $this->assertDatabaseHas('financial_audits', ['record_type' => 'expense', 'record_id' => $expense->id, 'actor_id' => $actor->id, 'action' => 'expense.recorded']);
});

test('expenses support backdating and no academic period or payment method', function () {
    $actor = expenseActor();
    $input = [...expenseInput(), 'expense_date' => '2020-01-01', 'payment_method' => ''];

    $expense = app(SaveExpense::class)->handle($actor, $input);

    expect($expense->expense_date->toDateString())->toBe('2020-01-01');
    expect($expense->payment_method)->toBeNull();
    expect($expense->academic_year_id)->toBeNull();
});

test('expense input rejects invalid financial values without saving', function (string $field, mixed $value) {
    $actor = expenseActor();
    $input = [...expenseInput(), $field => $value];

    try {
        app(SaveExpense::class)->handle($actor, $input);
        $this->fail('Invalid expense input was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    $this->assertDatabaseCount('expenses', 0);
    $this->assertDatabaseCount('financial_audits', 0);
})->with([
    'zero' => ['amount', '0.00'], 'negative' => ['amount', '-1.00'],
    'precision' => ['amount', '1.005'], 'float' => ['amount', 1.25],
    'overflow' => ['amount', '10000000000.00'], 'missing amount' => ['amount', ''],
    'missing description' => ['description', '  '], 'long description' => ['description', str_repeat('a', 501)],
    'missing date' => ['expense_date', ''], 'invalid date' => ['expense_date', '2026-02-30'],
    'future date' => ['expense_date', '2999-01-01'], 'invalid category' => ['expense_category_id', 999999],
    'invalid year' => ['academic_year_id', 999999], 'invalid term' => ['term_id', 999999],
    'invalid method' => ['payment_method', 'crypto'], 'invalid key' => ['submission_key', 'wrong'],
]);

test('inactive categories and terms outside the selected year are rejected', function () {
    $actor = expenseActor();
    $inactive = ExpenseCategory::factory()->inactive()->create();
    expect(fn () => app(SaveExpense::class)->handle($actor, expenseInput($inactive)))
        ->toThrow(ValidationException::class, 'Select an active expense category.');
    $term = Term::factory()->create();
    $year = AcademicYear::factory()->create();
    $input = expenseInput();

    expect(fn () => app(SaveExpense::class)->handle($actor, [...$input, 'academic_year_id' => $year->id, 'term_id' => $term->id]))->toThrow(ValidationException::class);
    expect(fn () => app(SaveExpense::class)->handle($actor, [...$input, 'term_id' => $term->id]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('expenses', 0);
});

test('draft edits preserve before and after values and recording freezes the expense', function () {
    $actor = expenseActor();
    $input = expenseInput();
    $draft = app(SaveExpense::class)->handle($actor, $input, false);
    expect(FinanceSummary::totals()['expenses'])->toBe('0.00');

    $changed = app(SaveExpense::class)->handle($actor, [...$input, 'amount' => '140.50'], false, $draft->id);

    expect($changed->amount)->toBe('140.50');
    $changes = json_decode(DB::table('financial_audits')->latest('id')->value('changes'), true);
    expect($changes['before']['amount'])->toBe('120.35');
    expect($changes['after']['amount'])->toBe('140.50');
    $recorded = app(SaveExpense::class)->handle($actor, [...$input, 'amount' => '140.50'], true, $draft->id);
    expect($recorded->status)->toBe(ExpenseStatus::Recorded);
    expect(fn () => app(SaveExpense::class)->handle($actor, $input, false, $draft->id))->toThrow(AuthorizationException::class);
    expect(fn () => $recorded->forceFill(['amount' => '1.00'])->save())->toThrow(ValidationException::class);
    expect(FinanceSummary::totals()['expenses'])->toBe('140.50');
});

test('duplicate submissions return one expense and cannot expose another users record', function () {
    $actor = expenseActor();
    $input = expenseInput();
    $first = app(SaveExpense::class)->handle($actor, $input);

    expect(app(SaveExpense::class)->handle($actor, $input)->id)->toBe($first->id);
    expect(fn () => app(SaveExpense::class)->handle(expenseActor(), $input))->toThrow(HttpException::class);
    $this->assertDatabaseCount('expenses', 1);
    $this->assertDatabaseCount('financial_audits', 1);
});

test('voiding is audited idempotent and preserves details while excluding the expense from totals', function () {
    $actor = expenseActor();
    $expense = app(SaveExpense::class)->handle($actor, expenseInput());

    app(VoidExpense::class)->handle($actor, $expense->id, 'Wrong supplier invoice');
    app(VoidExpense::class)->handle($actor, $expense->id, 'Repeated request');

    $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'amount' => '120.35', 'voided_by_user_id' => $actor->id, 'void_reason' => 'Wrong supplier invoice', 'status' => 'voided']);
    expect(FinanceSummary::totals()['expenses'])->toBe('0.00');
    expect($expense->fresh()->voidedBy->id)->toBe($actor->id);
    $this->assertDatabaseCount('financial_audits', 2);
    expect(fn () => $expense->fresh()->delete())->toThrow(ValidationException::class);
    expect(fn () => $expense->fresh()->forceFill(['status' => ExpenseStatus::Recorded])->save())->toThrow(ValidationException::class);
});

test('voiding needs a reason and cannot finalize a draft', function () {
    $actor = expenseActor();
    $expense = Expense::factory()->create();

    expect(fn () => app(VoidExpense::class)->handle($actor, $expense->id, ''))->toThrow(ValidationException::class);
    $draft = Expense::factory()->draft()->create();
    expect(fn () => app(VoidExpense::class)->handle($actor, $draft->id, 'Not recorded yet'))->toThrow(AuthorizationException::class);
    $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'status' => 'recorded']);
    $this->assertDatabaseHas('expenses', ['id' => $draft->id, 'status' => 'draft']);
});

test('only drafts can be deleted and their audit snapshot remains', function () {
    $actor = expenseActor();
    $draft = Expense::factory()->draft()->create();
    $recorded = Expense::factory()->create();

    app(SaveExpense::class)->deleteDraft($actor, $draft->id);

    $this->assertModelMissing($draft);
    $this->assertDatabaseHas('financial_audits', ['record_id' => $draft->id, 'action' => 'expense.draft_deleted']);
    expect(fn () => app(SaveExpense::class)->deleteDraft($actor, $recorded->id))->toThrow(AuthorizationException::class);
    $this->assertModelExists($recorded);
});

test('expense policies enforce separate capabilities and lifecycle rules', function (string $permission, string $ability, string $state) {
    $expense = $state === 'draft' ? Expense::factory()->draft()->create() : Expense::factory()->create();
    $allowed = expenseActor([$permission]);
    $denied = expenseActor([]);

    expect(Gate::forUser($allowed)->allows($ability, $expense))->toBeTrue();
    expect(Gate::forUser($denied)->allows($ability, $expense))->toBeFalse();
})->with([
    [Permissions::EXPENSES_VIEW, 'view', 'recorded'],
    [Permissions::EXPENSES_UPDATE, 'update', 'draft'],
    [Permissions::EXPENSES_DELETE, 'delete', 'draft'],
    [Permissions::EXPENSES_VOID, 'void', 'recorded'],
]);

test('write actions reject users without their specific permissions', function () {
    $actor = expenseActor([Permissions::EXPENSES_VIEW]);
    $draft = Expense::factory()->draft()->create();
    $recorded = Expense::factory()->create();
    $input = expenseInput();

    expect(fn () => app(SaveExpense::class)->handle($actor, $input))->toThrow(AuthorizationException::class);
    expect(fn () => app(SaveExpense::class)->handle($actor, $input, false, $draft->id))->toThrow(AuthorizationException::class);
    expect(fn () => app(SaveExpense::class)->deleteDraft($actor, $draft->id))->toThrow(AuthorizationException::class);
    expect(fn () => app(VoidExpense::class)->handle($actor, $recorded->id, 'Incorrect entry'))->toThrow(AuthorizationException::class);
    expect(fn () => app(ManageExpenseCategory::class)->save($actor, null, 'Utilities', '', true))->toThrow(AuthorizationException::class);
    expect(fn () => app(ManageExpenseCategory::class)->delete($actor, $recorded->expense_category_id))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('expenses', 2);
    $this->assertDatabaseCount('financial_audits', 0);
});

test('categories normalize duplicates and retain history after rename and deactivation', function () {
    $actor = expenseActor();
    $manager = app(ManageExpenseCategory::class);
    $category = $manager->save($actor, null, '  Teaching   Materials ', '', true);
    expect($category->name)->toBe('Teaching Materials');
    expect(fn () => $manager->save($actor, null, 'teaching materials', '', true))->toThrow(ValidationException::class);
    $expense = app(SaveExpense::class)->handle($actor, expenseInput($category));

    $manager->save($actor, $category->id, 'Books and Materials', '', false);

    expect($expense->fresh()->category_name)->toBe('Teaching Materials');
    expect(FinanceSummary::totals()['expenses'])->toBe('120.35');
    expect(fn () => $manager->delete($actor, $category->id))->toThrow(ValidationException::class);
    expect(fn () => DB::table('expense_categories')->where('id', $category->id)->delete())->toThrow(QueryException::class);
    $unused = ExpenseCategory::factory()->create();
    $manager->delete($actor, $unused->id);
    $this->assertModelMissing($unused);
    $this->assertModelExists($category);
});

test('an audit write failure rolls back the expense transaction', function () {
    $actor = expenseActor();
    $input = expenseInput();
    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with($query->sql, 'insert into') && str_contains($query->sql, 'financial_audits')) {
            throw new RuntimeException('Audit storage unavailable.');
        }
    });

    expect(fn () => app(SaveExpense::class)->handle($actor, $input))->toThrow(RuntimeException::class, 'Audit storage unavailable.');

    $this->assertDatabaseCount('expenses', 0);
    $this->assertDatabaseCount('financial_audits', 0);
});

test('an audit failure preserves the original draft when recording an edit', function () {
    $actor = expenseActor();
    $input = expenseInput();
    $draft = app(SaveExpense::class)->handle($actor, $input, false);
    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with($query->sql, 'insert into') && str_contains($query->sql, 'financial_audits')) {
            throw new RuntimeException('Audit storage unavailable.');
        }
    });

    expect(fn () => app(SaveExpense::class)->handle($actor, [...$input, 'amount' => '200.00'], true, $draft->id))
        ->toThrow(RuntimeException::class, 'Audit storage unavailable.');

    $this->assertDatabaseHas('expenses', ['id' => $draft->id, 'amount' => '120.35', 'status' => 'draft', 'recorded_at' => null]);
    $this->assertDatabaseCount('financial_audits', 1);
    expect(FinanceSummary::totals()['expenses'])->toBe('0.00');
});

test('an audit failure leaves a recorded expense authoritative when voiding fails', function () {
    $actor = expenseActor();
    $expense = app(SaveExpense::class)->handle($actor, expenseInput());
    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with($query->sql, 'insert into') && str_contains($query->sql, 'financial_audits')) {
            throw new RuntimeException('Audit storage unavailable.');
        }
    });

    expect(fn () => app(VoidExpense::class)->handle($actor, $expense->id, 'Incorrect supplier receipt'))
        ->toThrow(RuntimeException::class, 'Audit storage unavailable.');

    $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'status' => 'recorded', 'voided_at' => null,
        'voided_by_user_id' => null, 'void_reason' => null]);
    $this->assertDatabaseCount('financial_audits', 1);
    expect(FinanceSummary::totals()['expenses'])->toBe('120.35');
});

test('finance combines actual payments and recorded expenses excluding voids and drafts', function () {
    $actor = expenseActor();
    $invoice = InvoiceItem::factory()->create(['amount' => '1000.00', 'unit_amount' => '1000.00'])->invoice;
    $data = ['student_id' => $invoice->student_id, 'invoice_id' => $invoice->id,
        'payment_date' => today()->toDateString(), 'payment_method' => 'cash'];
    app(RecordPayment::class)->handle($actor, [...$data, 'amount' => '300.25', 'submission_key' => (string) Str::uuid()]);
    $voided = app(RecordPayment::class)->handle($actor, [...$data, 'amount' => '50.00', 'submission_key' => (string) Str::uuid()]);
    app(VoidPayment::class)->handle($actor, $voided->id, 'Incorrect payment');
    Expense::factory()->create(['amount' => '125.75']);
    Expense::factory()->draft()->create(['amount' => '500.00']);
    Expense::factory()->voided()->create(['amount' => '500.00']);

    expect(FinanceSummary::totals())->toBe(['income' => '300.25', 'expenses' => '125.75', 'net' => '174.50']);
});

test('period income uses only matching allocations and date ranges are inclusive', function () {
    $first = Invoice::factory()->create();
    $secondTerm = Term::factory()->second()->create(['academic_year_id' => $first->academic_year_id]);
    $second = Invoice::factory()->create(['enrollment_id' => $first->enrollment_id, 'term_id' => $secondTerm->id]);
    $payment = Payment::factory()->create(['student_id' => $first->student_id, 'amount' => '200.00', 'payment_date' => '2025-09-01']);
    PaymentAllocation::factory()->create(['invoice_id' => $first->id, 'payment_id' => $payment->id, 'amount' => '70.00']);
    PaymentAllocation::factory()->create(['invoice_id' => $second->id, 'payment_id' => $payment->id, 'amount' => '130.00']);
    Expense::factory()->create(['academic_year_id' => $first->academic_year_id, 'term_id' => $first->term_id, 'amount' => '30.25', 'expense_date' => '2025-09-01']);
    Expense::factory()->create(['academic_year_id' => $first->academic_year_id, 'term_id' => $second->term_id, 'amount' => '40.50', 'expense_date' => '2025-09-02']);
    $filters = ['academic_year_id' => (string) $first->academic_year_id];

    expect(FinanceSummary::totals($filters))->toBe(['income' => '200.00', 'expenses' => '70.75', 'net' => '129.25']);
    expect(FinanceSummary::totals([...$filters, 'term_id' => (string) $first->term_id]))->toBe(['income' => '70.00', 'expenses' => '30.25', 'net' => '39.75']);
    expect(FinanceSummary::totals([...$filters, 'date_from' => '2025-09-01', 'date_to' => '2025-09-01']))->toBe(['income' => '200.00', 'expenses' => '30.25', 'net' => '169.75']);
    expect(FinanceSummary::totals(['academic_year_id' => (string) AcademicYear::factory()->create()->id]))->toBe(['income' => '0.00', 'expenses' => '0.00', 'net' => '0.00']);
});

test('expense category and date breakdowns use recorded amounts only', function () {
    $category = ExpenseCategory::factory()->create();
    Expense::factory()->count(2)->create(['expense_category_id' => $category->id, 'amount' => '10.15', 'expense_date' => '2025-02-01']);
    Expense::factory()->voided()->create(['expense_category_id' => $category->id]);
    Expense::factory()->draft()->create(['expense_category_id' => $category->id]);

    expect(Money::minor((string) FinanceSummary::byCategory()->sole()->total))->toBe(2030);
    expect(Money::minor((string) FinanceSummary::byDate()->sole()->total))->toBe(2030);
});
