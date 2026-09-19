<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Invoice> */
class InvoiceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['enrollment_id' => Enrollment::factory(), 'student_id' => fn (array $attributes) => Enrollment::query()->whereKey($attributes['enrollment_id'])->firstOrFail()->student_id, 'academic_year_id' => fn (array $attributes) => Enrollment::query()->whereKey($attributes['enrollment_id'])->firstOrFail()->academic_year_id, 'class_level_id' => fn (array $attributes) => Enrollment::query()->whereKey($attributes['enrollment_id'])->firstOrFail()->class_level_id, 'term_id' => fn (array $attributes) => Term::factory()->create(['academic_year_id' => $attributes['academic_year_id']])->id, 'invoice_number' => 'INV-'.Str::ulid(), 'student_name' => fn (array $attributes) => Student::query()->whereKey($attributes['student_id'])->firstOrFail()->fullName(), 'admission_number' => fn (array $attributes) => Student::query()->whereKey($attributes['student_id'])->firstOrFail()->admission_number, 'class_name' => fn (array $attributes) => ClassLevel::query()->whereKey($attributes['class_level_id'])->firstOrFail()->name, 'academic_year_name' => fn (array $attributes) => AcademicYear::query()->whereKey($attributes['academic_year_id'])->firstOrFail()->name, 'term_name' => fn (array $attributes) => Term::query()->whereKey($attributes['term_id'])->firstOrFail()->name, 'issue_date' => today()->toDateString(), 'created_by_user_id' => User::factory()];
    }
}
