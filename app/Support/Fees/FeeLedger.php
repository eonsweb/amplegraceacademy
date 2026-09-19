<?php

namespace App\Support\Fees;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class FeeLedger
{
    /** Balances and status are derived, never writable columns.
     * @return Builder<Invoice>
     */
    public static function invoices(): Builder
    {
        $charges = DB::table('invoice_items')->select('invoice_id')->selectRaw('SUM(amount) AS total')->groupBy('invoice_id');
        $allocations = DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereNull('payments.voided_at')->select('invoice_id')->selectRaw('SUM(payment_allocations.amount) AS paid')->groupBy('invoice_id');
        $ledger = DB::table('invoices')->leftJoinSub($charges, 'charges', 'charges.invoice_id', '=', 'invoices.id')
            ->leftJoinSub($allocations, 'allocations', 'allocations.invoice_id', '=', 'invoices.id')
            ->select('invoices.*')->selectRaw('COALESCE(charges.total,0) AS total, COALESCE(allocations.paid,0) AS paid, COALESCE(charges.total,0)-COALESCE(allocations.paid,0) AS outstanding');

        return Invoice::query()->fromSub($ledger, 'invoices')->select('invoices.*');
    }

    /** @param Builder<Invoice> $query
     * @return array{total: string, paid: string, outstanding: string, owing: int}
     */
    public static function summary(Builder $query): array
    {
        $row = DB::query()->fromSub((clone $query)->whereNull('invoices.voided_at')->toBase(), 'ledger')
            ->selectRaw('COALESCE(SUM(total),0) AS total, COALESCE(SUM(paid),0) AS paid, COALESCE(SUM(outstanding),0) AS outstanding, COUNT(DISTINCT CASE WHEN outstanding > 0 THEN student_id END) AS owing')->first();

        return ['total' => (string) $row->total, 'paid' => (string) $row->paid, 'outstanding' => (string) $row->outstanding, 'owing' => (int) $row->owing];
    }

    /** @return Builder<Payment> */
    public static function payments(string $year = '', string $term = '', ?int $studentId = null): Builder
    {
        return Payment::query()->when($studentId !== null, fn (Builder $q) => $q->where('student_id', $studentId))
            ->when($year !== '' || $term !== '', fn (Builder $q) => $q->whereHas('allocations.invoice', fn (Builder $invoice) => $invoice
                ->when($year !== '', fn (Builder $q) => $q->where('academic_year_id', $year))
                ->when($term !== '', fn (Builder $q) => $q->where('term_id', $term))));
    }

    /** @param array<string, mixed> $changes */
    public static function audit(User $actor, string $action, string $type, int $id, array $changes = []): void
    {
        DB::table('financial_audits')->insert(['actor_id' => $actor->id, 'action' => $action, 'record_type' => $type, 'record_id' => $id, 'changes' => json_encode($changes, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
