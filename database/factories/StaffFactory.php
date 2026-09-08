<?php

namespace Database\Factories;

use App\EmploymentType;
use App\Gender;
use App\Models\Staff;
use App\StaffStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_number' => fake()->unique()->numerify('STF######'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'gender' => fake()->randomElement(Gender::cases()),
            'date_of_birth' => fake()->dateTimeBetween('-65 years', '-20 years'),
            'email' => fake()->optional()->safeEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'address' => fake()->optional()->address(),
            'role_title' => fake()->randomElement(['Teacher', 'Administrator', 'Accountant', 'Secretary']),
            'department' => fake()->randomElement(['Teaching', 'Administration', 'Finance', 'Support']),
            'employment_type' => fake()->randomElement(EmploymentType::cases()),
            'employment_date' => fake()->dateTimeBetween('-15 years', 'now'),
            'photo' => null,
            'status' => StaffStatus::Active,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => StaffStatus::Active]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => StaffStatus::Inactive]);
    }

    public function teacher(): static
    {
        return $this->state(fn (): array => ['role_title' => 'Teacher', 'department' => 'Teaching']);
    }
}
