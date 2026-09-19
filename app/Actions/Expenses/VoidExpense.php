<?php

namespace App\Actions\Expenses;

use App\ExpenseStatus;
use App\Models\Expense;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Fees\FeeLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class VoidExpense
{
    public function handle(User $actor, int $expenseId, string $reason): void
    {
        Gate::forUser($actor)->authorize(Permissions::EXPENSES_VOID);
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:500']])->validate();
        DB::transaction(function () use ($actor, $expenseId, $reason): void {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expenseId);
            if ($expense->status === ExpenseStatus::Voided) {
                return;
            }
            Gate::forUser($actor)->authorize('void', $expense);
            $before = $expense->attributesToArray();
            $expense->forceFill(['status' => ExpenseStatus::Voided, 'voided_at' => now(),
                'voided_by_user_id' => $actor->id, 'void_reason' => $reason])->save();
            FeeLedger::audit($actor, 'expense.voided', 'expense', $expense->id, ['before' => $before, 'after' => $expense->attributesToArray()]);
        }, 3);
    }
}
