<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssessmentScore> */
class AssessmentScoreFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'assessment_id' => Assessment::factory(),
            'enrollment_id' => function (array $attributes): int {
                $assessment = Assessment::query()->whereKey($attributes['assessment_id'])->firstOrFail();

                return Enrollment::factory()->create(['academic_year_id' => $assessment->academic_year_id, 'class_level_id' => $assessment->class_level_id])->id;
            },
            'score' => '10.00',
            'entered_by' => null,
        ];
    }
}
