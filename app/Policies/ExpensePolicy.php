<?php

namespace App\Policies;

use App\ExpenseStatus;
use App\Models\Expense;
use App\Models\User;
use App\Support\Authorization\Permissions;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::EXPENSES_VIEW);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $user->can(Permissions::EXPENSES_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::EXPENSES_CREATE);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->can(Permissions::EXPENSES_UPDATE) && $expense->status === ExpenseStatus::Draft;
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $user->can(Permissions::EXPENSES_DELETE) && $expense->status === ExpenseStatus::Draft;
    }

    public function void(User $user, Expense $expense): bool
    {
        return $user->can(Permissions::EXPENSES_VOID) && $expense->status === ExpenseStatus::Recorded;
    }
}
