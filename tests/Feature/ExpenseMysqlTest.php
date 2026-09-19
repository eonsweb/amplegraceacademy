<?php

use App\Actions\Expenses\SaveExpense;
use App\Actions\Expenses\VoidExpense;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Term;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Finance\FinanceSummary;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

test('mysql preserves expense constraints locking and authoritative period totals', function () {
    if (! filter_var(getenv('EXPENSES_MYSQL_TESTS'), FILTER_VALIDATE_BOOLEAN)) {
        $this->markTestSkipped('Set EXPENSES_MYSQL_TESTS=1 to use an isolated temporary MySQL database.');
    }

    $originalConnection = DB::getDefaultConnection();
    $database = 'expenses_test_'.bin2hex(random_bytes(8));
    $server = array_replace(config('database.connections.mysql'), ['url' => null, 'database' => null]);
    config(['database.connections.expenses_server' => $server]);
    DB::connection('expenses_server')->statement("CREATE DATABASE `$database`");

    try {
        $connection = array_replace($server, ['database' => $database]);
        config(['database.connections.expenses_writer' => $connection, 'database.connections.expenses_contender' => $connection]);
        DB::setDefaultConnection('expenses_writer');
        expect(Artisan::call('migrate', ['--database' => 'expenses_writer', '--force' => true, '--no-interaction' => true]))->toBe(0);
        foreach ([Permissions::EXPENSES_CREATE, Permissions::EXPENSES_UPDATE, Permissions::EXPENSES_VOID,
            Permissions::EXPENSES_VIEW, Permissions::FINANCIAL_REPORTS_VIEW] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $actor = User::factory()->create();
        $actor->givePermissionTo([Permissions::EXPENSES_CREATE, Permissions::EXPENSES_UPDATE, Permissions::EXPENSES_VOID,
            Permissions::EXPENSES_VIEW, Permissions::FINANCIAL_REPORTS_VIEW]);
        $actor->can(Permissions::EXPENSES_CREATE);
        $category = ExpenseCategory::factory()->create();
        $term = Term::factory()->create();
        $data = ['expense_category_id' => $category->id, 'academic_year_id' => $term->academic_year_id,
            'term_id' => $term->id, 'amount' => '30.25', 'description' => 'Library books',
            'expense_date' => '2025-09-01', 'submission_key' => (string) Str::uuid()];
        $writer = DB::connection('expenses_writer');
        $writer->beginTransaction();
        $expense = app(SaveExpense::class)->handle($actor, $data);

        DB::setDefaultConnection('expenses_contender');
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            app(SaveExpense::class)->handle($actor, $data);
            $this->fail('A concurrent retry must wait for the recorder lock.');
        } catch (QueryException $exception) {
            expect($exception->errorInfo[1])->toBe(1205);
        }
        $writer->commit();
        expect(app(SaveExpense::class)->handle($actor, $data)->id)->toBe($expense->id);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('financial_audits', 1);

        $invoice = Invoice::factory()->create(['academic_year_id' => $term->academic_year_id, 'term_id' => $term->id]);
        $otherTerm = Term::factory()->second()->create(['academic_year_id' => $term->academic_year_id]);
        $otherInvoice = Invoice::factory()->create(['enrollment_id' => $invoice->enrollment_id, 'term_id' => $otherTerm->id,
            'academic_year_id' => $term->academic_year_id]);
        $payment = Payment::factory()->create(['student_id' => $invoice->student_id, 'amount' => '200.00', 'payment_date' => '2025-09-01']);
        PaymentAllocation::factory()->create(['invoice_id' => $invoice->id, 'payment_id' => $payment->id, 'amount' => '70.00']);
        PaymentAllocation::factory()->create(['invoice_id' => $otherInvoice->id, 'payment_id' => $payment->id, 'amount' => '130.00']);
        $filters = ['academic_year_id' => (string) $term->academic_year_id, 'term_id' => (string) $term->id,
            'date_from' => '2025-09-01', 'date_to' => '2025-09-01'];
        expect(FinanceSummary::totals($filters))->toBe(['income' => '70.00', 'expenses' => '30.25', 'net' => '39.75']);
        expect(FinanceSummary::byCategory($filters)->sole()->total)->toBe('30.25');
        expect(FinanceSummary::byDate($filters)->sole()->total)->toBe('30.25');
        $this->actingAs($actor);
        Livewire::test('pages::expenses.index')->assertSee('Library books');
        Livewire::test('pages::finance.overview')->assertSee('Financial Overview');

        expect(fn () => DB::table('expenses')->where('id', $expense->id)->update(['amount' => '-1.00']))->toThrow(QueryException::class);
        expect(fn () => DB::table('expenses')->where('id', $expense->id)->update(['status' => 'invented']))->toThrow(QueryException::class);
        expect(fn () => DB::table('expenses')->where('id', $expense->id)->update(['status' => 'voided']))->toThrow(QueryException::class);
        expect(fn () => DB::table('expenses')->where('id', $expense->id)->update(['academic_year_id' => null]))->toThrow(QueryException::class);
        expect(fn () => DB::table('expense_categories')->where('id', $category->id)->delete())->toThrow(QueryException::class);
        expect(fn () => DB::table('users')->where('id', $actor->id)->delete())->toThrow(QueryException::class);
        expect(fn () => DB::table('terms')->where('id', $term->id)->delete())->toThrow(QueryException::class);
        expect(fn () => ExpenseCategory::factory()->create(['name' => $category->name]))->toThrow(QueryException::class);

        $draft = app(SaveExpense::class)->handle($actor, [...$data, 'submission_key' => (string) Str::uuid()], false);
        DB::setDefaultConnection('expenses_writer');
        $writer->beginTransaction();
        app(SaveExpense::class)->handle($actor, [...$data, 'amount' => '40.00'], true, $draft->id);
        DB::setDefaultConnection('expenses_contender');
        try {
            app(SaveExpense::class)->handle($actor, [...$data, 'amount' => '1.00'], false, $draft->id);
            $this->fail('A draft edit must not bypass a concurrent recording.');
        } catch (QueryException $exception) {
            expect($exception->errorInfo[1])->toBe(1205);
        }
        $writer->commit();
        expect(fn () => app(SaveExpense::class)->handle($actor, $data, false, $draft->id))->toThrow(AuthorizationException::class);

        app(VoidExpense::class)->handle($actor, $expense->id, 'Incorrect entry');
        $this->assertModelExists($expense);
        expect(FinanceSummary::totals($filters)['expenses'])->toBe('40.00');
        expect(Expense::query()->findOrFail($expense->id)->amount)->toBe('30.25');
    } finally {
        foreach (['expenses_writer', 'expenses_contender'] as $name) {
            DB::purge($name);
        }
        DB::setDefaultConnection($originalConnection);
        DB::connection('expenses_server')->statement("DROP DATABASE `$database`");
        DB::purge('expenses_server');
    }
});
