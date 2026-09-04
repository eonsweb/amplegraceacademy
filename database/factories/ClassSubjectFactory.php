<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\ClassSubject;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassSubject>
 */
class ClassSubjectFactory extends Factory
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
            'class_level_id' => ClassLevel::factory(),
            'subject_id' => Subject::factory(),
            'staff_id' => null,
            'is_elective' => false,
        ];
    }
}
