<?php

namespace App\Policies;

use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\Authorization\Permissions;

class ExpenseCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::EXPENSE_CATEGORIES_MANAGE);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::EXPENSE_CATEGORIES_MANAGE);
    }

    public function update(User $user, ExpenseCategory $category): bool
    {
        return $user->can(Permissions::EXPENSE_CATEGORIES_MANAGE);
    }

    public function delete(User $user, ExpenseCategory $category): bool
    {
        return $user->can(Permissions::EXPENSE_CATEGORIES_MANAGE);
    }
}
