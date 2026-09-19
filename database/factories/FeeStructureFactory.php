<?php

namespace Database\Factories;

use App\Models\ClassLevel;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FeeStructure> */
class FeeStructureFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['term_id' => Term::factory(), 'academic_year_id' => fn (array $attributes) => Term::query()->whereKey($attributes['term_id'])->firstOrFail()->academic_year_id, 'class_level_id' => ClassLevel::factory(), 'fee_type_id' => FeeType::factory(), 'amount' => '100.00', 'is_active' => true];
    }
}
