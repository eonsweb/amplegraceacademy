<?php

namespace App\Actions\Expenses;

use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\Fees\FeeLedger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageExpenseCategory
{
    public function save(User $actor, ?int $id, string $name, string $description, bool $active): ExpenseCategory
    {
        Gate::forUser($actor)->authorize($id === null ? 'create' : 'update', $id === null ? ExpenseCategory::class : ExpenseCategory::query()->findOrFail($id));
        $name = Str::squish($name);
        Validator::make(['name' => $name, 'normalized_name' => Str::lower($name), 'description' => $description], [
            'name' => ['required', 'string', 'max:100'],
            'normalized_name' => [Rule::unique('expense_categories')->ignore($id)],
            'description' => ['nullable', 'string', 'max:2000'],
        ], ['normalized_name.unique' => 'An expense category with this name already exists.'])->validate();

        try {
            return DB::transaction(function () use ($actor, $id, $name, $description, $active): ExpenseCategory {
                $category = $id === null ? new ExpenseCategory : ExpenseCategory::query()->lockForUpdate()->findOrFail($id);
                $before = $category->exists ? $category->getAttributes() : null;
                $category->fill(['name' => $name, 'description' => trim($description) ?: null, 'is_active' => $active])->save();
                FeeLedger::audit($actor, 'expense_category.saved', 'expense_category', $category->id, ['before' => $before, 'after' => $category->getAttributes()]);

                return $category;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['normalized_name' => 'An expense category with this name already exists.']);
        }
    }

    public function delete(User $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id): void {
            $category = ExpenseCategory::query()->lockForUpdate()->findOrFail($id);
            Gate::forUser($actor)->authorize('delete', $category);
            if ($category->expenses()->exists()) {
                throw ValidationException::withMessages(['delete' => 'This category has expense history. Deactivate it instead.']);
            }
            FeeLedger::audit($actor, 'expense_category.deleted', 'expense_category', $category->id, ['before' => $category->getAttributes()]);
            $category->delete();
        }, 3);
    }
}
