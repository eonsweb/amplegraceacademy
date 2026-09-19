<?php

namespace Database\Factories;

use App\Models\FeeType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FeeType> */
class FeeTypeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => fake()->unique()->words(2, true), 'code' => strtoupper(fake()->unique()->bothify('FEE-??????')), 'description' => null, 'is_active' => true];
    }
}
