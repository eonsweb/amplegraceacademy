<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicYear>
 */
class AcademicYearFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startingYear = fake()->unique()->numberBetween(1900, 2198);

        return [
            'name' => $startingYear.'/'.($startingYear + 1),
            'is_current' => null,
        ];
    }
}
