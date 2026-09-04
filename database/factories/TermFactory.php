<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Term>
 */
class TermFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'name' => Term::STANDARD_TERMS[1],
            'term_order' => 1,
            'is_current' => null,
        ];
    }

    public function second(): static
    {
        return $this->state(fn (): array => ['name' => Term::STANDARD_TERMS[2], 'term_order' => 2]);
    }

    public function third(): static
    {
        return $this->state(fn (): array => ['name' => Term::STANDARD_TERMS[3], 'term_order' => 3]);
    }
}
