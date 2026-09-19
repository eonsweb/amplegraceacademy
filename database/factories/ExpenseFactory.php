<?php

namespace Database\Factories;

use App\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Expense> */
class ExpenseFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'expense_category_id' => ExpenseCategory::factory(), 'academic_year_id' => null, 'term_id' => null,
            'expense_date' => today()->toDateString(), 'amount' => '125.75', 'description' => 'Teaching materials',
            'payee' => 'School supplier', 'payment_method' => PaymentMethod::Cash,
            'status' => ExpenseStatus::Recorded, 'recorded_at' => now(), 'submission_key' => (string) Str::uuid(),
            'recorded_by_user_id' => User::factory(),
            'recorded_by_name' => fn (array $attributes) => User::query()->whereKey($attributes['recorded_by_user_id'])->firstOrFail()->name,
            'category_name' => fn (array $attributes) => ExpenseCategory::query()->whereKey($attributes['expense_category_id'])->firstOrFail()->name,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => ExpenseStatus::Draft, 'recorded_at' => null]);
    }

    public function voided(): static
    {
        return $this->state(fn (): array => ['status' => ExpenseStatus::Voided, 'voided_at' => now(), 'voided_by_user_id' => User::factory(), 'void_reason' => 'Incorrect expense entry']);
    }
}
