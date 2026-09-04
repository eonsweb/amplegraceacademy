<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Database\Seeder;

class EnrollmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $academicYear = AcademicYear::query()->where('is_current', true)->firstOrFail();
        $classLevelIds = ClassLevel::query()->where('is_active', true)->pluck('id');

        Student::query()->doesntHave('enrollments')->each(function (Student $student) use ($academicYear, $classLevelIds): void {
            Enrollment::factory()->create([
                'student_id' => $student,
                'academic_year_id' => $academicYear,
                'class_level_id' => $classLevelIds->random(),
            ]);
        });
    }
}
