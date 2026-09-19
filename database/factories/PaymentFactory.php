<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['student_id' => Student::factory(), 'receipt_number' => 'RCT-'.Str::ulid(), 'submission_key' => (string) Str::uuid(), 'payment_date' => today()->toDateString(), 'amount' => '100.00', 'payment_method' => PaymentMethod::Cash, 'received_by_user_id' => User::factory(), 'received_by_name' => fn (array $attributes) => User::query()->whereKey($attributes['received_by_user_id'])->firstOrFail()->name];
    }
}
