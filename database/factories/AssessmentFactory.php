<?php

namespace Database\Factories;

use App\AssessmentStatus;
use App\AssessmentType;
use App\Models\Assessment;
use App\Models\ClassSubject;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Assessment> */
class AssessmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'academic_year_id' => fn (): int => ClassSubject::factory()->create()->academic_year_id,
            'term_id' => fn (array $attributes): int => Term::factory()->create(['academic_year_id' => $attributes['academic_year_id']])->id,
            'class_level_id' => fn (array $attributes): int => ClassSubject::query()->where('academic_year_id', $attributes['academic_year_id'])->firstOrFail()->class_level_id,
            'subject_id' => fn (array $attributes): int => ClassSubject::query()->where('academic_year_id', $attributes['academic_year_id'])->where('class_level_id', $attributes['class_level_id'])->firstOrFail()->subject_id,
            'name' => fake()->words(3, true),
            'type' => AssessmentType::Test,
            'maximum_score' => '20.00',
            'assessment_date' => null,
            'status' => AssessmentStatus::Open,
        ];
    }
}
