<?php

namespace Database\Factories;

use App\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'class_level_id' => ClassLevel::factory(),
            'enrollment_date' => fake()->dateTimeBetween('-1 year'),
            'status' => EnrollmentStatus::Active,
        ];
    }
}
