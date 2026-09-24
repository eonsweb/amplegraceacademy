<?php

namespace App\Support\Reports;

use App\ExpenseStatus;
use App\Models\Invoice;
use App\Support\Fees\FeeLedger;
use App\Support\Fees\Money;
use App\Support\Finance\FinanceSummary;
use App\Support\Settings\SystemSettings;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class FinancialReports
{
    /** @param array<string, string> $filters
     * @return EloquentBuilder<Invoice>
     */
    public static function invoices(array $filters): EloquentBuilder
    {
        $query = FeeLedger::invoices();
        ReportFilters::columns($query->getQuery(), 'invoices', $filters, ['academic_year_id', 'term_id', 'class_level_id']);
        ReportFilters::student($query->getQuery(), 'invoices.student_id', $filters);
        if (($filters['fee_type_id'] ?? '') !== '') {
            $query->whereHas('items', fn (EloquentBuilder $items) => $items->where('fee_type_id', $filters['fee_type_id']));
        }

        return $query;
    }

    /** @param array<string, string> $filters */
    public static function query(string $report, array $filters): Builder
    {
        if (in_array($report, ['fees', 'outstanding'], true)) {
            $invoices = self::invoices($filters);
            $status = $filters['status'] ?? '';
            if ($report === 'fees') {
                if ($status === 'void') {
                    $invoices->whereNotNull('voided_at');
                } elseif ($status !== '') {
                    $invoices->whereNull('voided_at');
                    match ($status) {
                        'paid' => $invoices->where('outstanding', 0),
                        'unpaid' => $invoices->where('paid', 0)->where('outstanding', '>', 0),
                        'partially_paid' => $invoices->where('paid', '>', 0)->where('outstanding', '>', 0),
                        default => null,
                    };
                }

                return $invoices->toBase()->select('invoices.*')->selectRaw('issue_date AS date')->orderByDesc('issue_date')->orderBy('id');
            }
            $query = $invoices->whereNull('voided_at')->toBase()->select('student_id', 'admission_number', 'student_name', 'academic_year_id', 'academic_year_name', 'class_level_id', 'class_name')
                ->selectRaw('SUM(total) AS total, SUM(paid) AS paid, SUM(outstanding) AS outstanding')
                ->groupBy('student_id', 'admission_number', 'student_name', 'academic_year_id', 'academic_year_name', 'class_level_id', 'class_name');
            if ($status !== '') {
                $query->havingRaw($status === 'paid' ? 'SUM(outstanding) = 0' : 'SUM(outstanding) > 0');
            }

            return $query->orderByDesc('outstanding')->orderBy('student_id')->orderBy('academic_year_id')->orderBy('class_level_id');
        }
        if ($report === 'payments') {
            $query = FinanceSummary::payments($filters)->join('students', 'students.id', '=', 'payments.student_id');
            ReportFilters::student($query, 'payments.student_id', $filters);
            ReportFilters::columns($query, 'payments', $filters, ['payment_method']);
            if (($filters['status'] ?? '') !== '') {
                $filters['status'] === 'void' ? $query->whereNotNull('payments.voided_at') : $query->whereNull('payments.voided_at');
            }
            $references = DB::table('payment_allocations')->join('invoices', 'invoices.id', '=', 'payment_allocations.invoice_id')
                ->whereColumn('payment_allocations.payment_id', 'payments.id')->selectRaw('GROUP_CONCAT(invoices.invoice_number)');
            $query->addSelect('students.admission_number', 'payments.payment_date as date', 'payments.received_by_name as recorder')
                ->selectSub($references, 'invoices');

            return EnrollmentReports::name($query)->orderByDesc('payments.payment_date')->orderBy('payments.id');
        }

        $query = FinanceSummary::expenses($filters)->toBase();
        ReportFilters::columns($query, 'expenses', $filters, ['recorded_by_user_id']);

        return $query->select('expenses.*')->selectRaw('expense_date AS date, recorded_by_name AS recorder')->orderByDesc('expense_date')->orderBy('id');
    }

    /** @param array<string, string> $filters
     * @return array<string, string>
     */
    public static function totals(string $report, array $filters): array
    {
        if (in_array($report, ['income-expenses', 'financial-summary'], true)) {
            $totals = FinanceSummary::totals($filters);
            if ($report === 'financial-summary') {
                $billing = FeeLedger::summary(self::invoices($filters));
                $totals = ['total' => $billing['total'], 'paid' => $billing['paid'], 'outstanding' => $billing['outstanding'], ...$totals];
            }

            return $totals;
        }
        $query = self::query($report, $filters)->reorder();
        $derived = DB::query()->fromSub($query, 'report_rows');
        $expressions = match ($report) {
            'fees' => 'COALESCE(SUM(CASE WHEN voided_at IS NULL THEN total ELSE 0 END),0) AS total,
                COALESCE(SUM(CASE WHEN voided_at IS NULL THEN paid ELSE 0 END),0) AS paid,
                COALESCE(SUM(CASE WHEN voided_at IS NULL THEN outstanding ELSE 0 END),0) AS outstanding',
            'outstanding' => 'COALESCE(SUM(total),0) AS total, COALESCE(SUM(paid),0) AS paid, COALESCE(SUM(outstanding),0) AS outstanding',
            'payments' => 'COALESCE(SUM(CASE WHEN voided_at IS NULL THEN period_amount ELSE 0 END),0) AS income',
            default => "COALESCE(SUM(CASE WHEN status = '".ExpenseStatus::Recorded->value."' THEN amount ELSE 0 END),0) AS expenses",
        };
        $row = $derived->selectRaw('COUNT(*) AS records, '.$expressions)->first();

        return array_map(fn ($value): string => (string) $value, (array) $row);
    }

    /** @param Collection<int, \stdClass> $rows
     * @return Collection<int, \stdClass>
     */
    public static function decorate(string $report, Collection $rows): Collection
    {
        return $rows->map(function (\stdClass $row) use ($report): \stdClass {
            if ($report === 'fees') {
                $invoice = new Invoice;
                $invoice->setRawAttributes((array) $row);
                $row->status = $invoice->status()->label();
            } elseif ($report === 'payments') {
                $row->status = $row->voided_at === null ? 'Valid' : 'Voided';
            }

            return $row;
        });
    }

    /** @return list<string> */
    public static function moneyColumns(): array
    {
        return ['total', 'paid', 'outstanding', 'amount', 'period_amount', 'income', 'expenses', 'net'];
    }

    public static function format(string $column, mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (in_array($column, self::moneyColumns(), true)) {
            return app(SystemSettings::class)->formatMoney(Money::decimal(Money::minor((string) $value)));
        }
        if ($column === 'date') {
            return app(SystemSettings::class)->formatDate((string) $value);
        }
        if (in_array($column, ['status', 'payment_method'], true)) {
            return str((string) $value)->replace('_', ' ')->ucfirst()->toString();
        }

        return (string) $value;
    }
}
