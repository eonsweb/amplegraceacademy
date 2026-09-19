<?php

namespace App\Support\Finance;

use App\ExpenseStatus;
use App\Models\Expense;
use App\Support\Fees\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class FinanceSummary
{
    /** @param array<string, string> $filters
     * @return Builder<Expense>
     */
    public static function expenses(array $filters = []): Builder
    {
        $query = Expense::query();
        foreach (['academic_year_id', 'term_id', 'expense_category_id', 'payment_method', 'status'] as $column) {
            if (($filters[$column] ?? '') !== '') {
                $query->where('expenses.'.$column, $filters[$column]);
            }
        }
        self::dates($query->getQuery(), 'expenses.expense_date', $filters);
        $search = mb_substr(trim($filters['search'] ?? ''), 0, 100);
        if ($search !== '') {
            /** Restrict contains search to the two descriptive fields; references use prefix matching. */
            $query->where(fn (Builder $query) => $query->where('description', 'like', '%'.$search.'%')
                ->orWhere('payee', 'like', '%'.$search.'%')->orWhere('reference', 'like', $search.'%'));
        }

        return $query;
    }

    /** @param array<string, string> $filters */
    public static function income(array $filters = []): QueryBuilder
    {
        $query = DB::table('payments')->whereNull('payments.voided_at');
        self::dates($query, 'payments.payment_date', $filters);
        if (($filters['academic_year_id'] ?? '') !== '' || ($filters['term_id'] ?? '') !== '') {
            $query->join('payment_allocations', 'payment_allocations.payment_id', '=', 'payments.id')
                ->join('invoices', 'invoices.id', '=', 'payment_allocations.invoice_id')
                ->whereNull('invoices.voided_at');
            foreach (['academic_year_id', 'term_id'] as $column) {
                if (($filters[$column] ?? '') !== '') {
                    $query->where('invoices.'.$column, $filters[$column]);
                }
            }

            return $query->selectRaw('COALESCE(SUM(payment_allocations.amount), 0)');
        }

        return $query->selectRaw('COALESCE(SUM(payments.amount), 0)');
    }

    /** @param array<string, string> $filters
     * @return array{income: string, expenses: string, net: string}
     */
    public static function totals(array $filters = []): array
    {
        /** One SQL statement gives both totals the same database snapshot. */
        $row = DB::query()->selectSub(self::income($filters), 'income')
            ->selectSub(self::expenses($filters)->where('expenses.status', ExpenseStatus::Recorded->value)
                ->selectRaw('COALESCE(SUM(expenses.amount), 0)'), 'expenses')->first();
        $income = Money::minor((string) $row->income);
        $expenses = Money::minor((string) $row->expenses);

        return ['income' => Money::decimal($income), 'expenses' => Money::decimal($expenses), 'net' => Money::decimal($income - $expenses)];
    }

    /** @param array<string, string> $filters */
    public static function byCategory(array $filters = []): QueryBuilder
    {
        return self::expenses($filters)->where('expenses.status', ExpenseStatus::Recorded->value)->toBase()
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->select('expenses.expense_category_id', 'expense_categories.name')
            ->selectRaw('SUM(expenses.amount) AS total')
            ->groupBy('expenses.expense_category_id', 'expense_categories.name')->orderByDesc('total')->orderBy('expenses.expense_category_id');
    }

    /** @param array<string, string> $filters */
    public static function byDate(array $filters = []): QueryBuilder
    {
        return self::expenses($filters)->where('expenses.status', ExpenseStatus::Recorded->value)->toBase()
            ->select('expense_date')->selectRaw('SUM(amount) AS total')->groupBy('expense_date')->orderByDesc('expense_date');
    }

    /** @param array<string, string> $filters */
    private static function dates(QueryBuilder $query, string $column, array $filters): void
    {
        if (($filters['invalid'] ?? '') === '1') {
            $query->whereRaw('1 = 0');
        }
        if (($filters['date_from'] ?? '') !== '') {
            $query->where($column, '>=', $filters['date_from']);
        }
        if (($filters['date_to'] ?? '') !== '') {
            $query->where($column, '<', Carbon::parse($filters['date_to'])->addDay()->toDateString());
        }
    }
}
