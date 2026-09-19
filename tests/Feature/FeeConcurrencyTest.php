<?php

use App\Actions\Fees\RecordPayment;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Fees\FeeLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

test('mysql serializes payments and rechecks balances and submission keys after contention', function () {
    if (! filter_var(getenv('FEES_MYSQL_TESTS'), FILTER_VALIDATE_BOOLEAN)) {
        $this->markTestSkipped('Set FEES_MYSQL_TESTS=1 to run against an isolated MySQL database.');
    }

    $originalConnection = DB::getDefaultConnection();
    $database = 'fees_test_'.bin2hex(random_bytes(8));
    $server = array_replace(config('database.connections.mysql'), ['url' => null, 'database' => null]);
    config(['database.connections.fees_server' => $server]);
    DB::connection('fees_server')->statement("CREATE DATABASE `$database`");

    try {
        $connection = array_replace($server, ['database' => $database]);
        config(['database.connections.fees_writer' => $connection, 'database.connections.fees_contender' => $connection]);
        DB::setDefaultConnection('fees_writer');
        expect(Artisan::call('migrate', ['--database' => 'fees_writer', '--force' => true, '--no-interaction' => true]))->toBe(0);
        Permission::findOrCreate(Permissions::PAYMENTS_RECORD, 'web');
        $actor = User::factory()->create();
        $actor->givePermissionTo(Permissions::PAYMENTS_RECORD);
        $actor->can(Permissions::PAYMENTS_RECORD);
        $invoice = InvoiceItem::factory()->create()->invoice;
        $data = ['student_id' => $invoice->student_id, 'invoice_id' => $invoice->id, 'amount' => '60.00',
            'payment_date' => today()->toDateString(), 'payment_method' => 'cash', 'submission_key' => (string) Str::uuid()];
        $writer = DB::connection('fees_writer');
        $writer->beginTransaction();
        $first = app(RecordPayment::class)->handle($actor, $data);

        DB::setDefaultConnection('fees_contender');
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $competing = array_replace($data, ['submission_key' => (string) Str::uuid()]);
        try {
            app(RecordPayment::class)->handle($actor, $competing);
            $this->fail('A competing connection must not bypass the invoice lock.');
        } catch (QueryException $exception) {
            expect($exception->errorInfo[1])->toBe(1205);
        }
        $writer->commit();

        expect(fn () => app(RecordPayment::class)->handle($actor, $competing))
            ->toThrow(ValidationException::class, 'Payment exceeds the outstanding invoice balance.');
        expect(app(RecordPayment::class)->handle($actor, $data)->id)->toBe($first->id);
        expect(FeeLedger::invoices()->findOrFail($invoice->id)->outstanding)->toBe('40.00');
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseCount('financial_audits', 1);

        Permission::findOrCreate(Permissions::FEES_VIEW, 'web');
        $actor->givePermissionTo(Permissions::FEES_VIEW);
        $this->actingAs($actor);
        $overview = Livewire::test('pages::fees.overview')
            ->set('academicYearId', (string) $invoice->academic_year_id)
            ->set('termId', (string) $invoice->term_id);
        expect($overview->get('paymentsToday'))->toBe('60.00');

        expect(fn () => DB::table('payments')->where('id', $first->id)->update(['amount' => '-1.00']))->toThrow(QueryException::class);
        expect(fn () => DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['amount' => '99.00']))->toThrow(QueryException::class);
        expect(fn () => DB::table('students')->where('id', $invoice->student_id)->delete())->toThrow(QueryException::class);
    } finally {
        foreach (['fees_writer', 'fees_contender'] as $name) {
            DB::purge($name);
        }
        DB::setDefaultConnection($originalConnection);
        DB::connection('fees_server')->statement("DROP DATABASE `$database`");
        DB::purge('fees_server');
    }
});
