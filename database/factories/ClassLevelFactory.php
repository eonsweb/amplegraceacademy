<?php

namespace Database\Factories;

use App\Models\ClassLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassLevel>
 */
class ClassLevelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->numerify('Grade ##'),
            'level_order' => fake()->unique()->numberBetween(1, 999),
            'is_active' => true,
        ];
    }
}
