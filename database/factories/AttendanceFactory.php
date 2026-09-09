<?php

namespace Database\Factories;

use App\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Enrollment;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'term_id' => fn (array $attributes): int => Term::factory()->create(['academic_year_id' => Enrollment::query()->whereKey($attributes['enrollment_id'])->value('academic_year_id')])->id,
            'attendance_date' => today()->toDateString(),
            'status' => AttendanceStatus::Present,
            'remark' => null,
        ];
    }
}
