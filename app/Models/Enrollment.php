<?php

namespace App\Models;

use App\EnrollmentStatus;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['student_id', 'academic_year_id', 'class_level_id', 'enrollment_date', 'status'])]
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Enrollment $enrollment): void {
            if ($enrollment->status !== EnrollmentStatus::Active) {
                return;
            }

            $hasActiveEnrollment = static::query()
                ->where('student_id', $enrollment->student_id)
                ->where('academic_year_id', $enrollment->academic_year_id)
                ->where('status', EnrollmentStatus::Active->value)
                ->when($enrollment->exists, fn ($query) => $query->whereKeyNot($enrollment->getKey()))
                ->exists();

            if ($hasActiveEnrollment) {
                throw ValidationException::withMessages([
                    'enrollment' => 'This student already has an active enrollment for the selected academic year.',
                ]);
            }
        });
    }

    protected function casts(): array
    {
        return ['enrollment_date' => 'date', 'status' => EnrollmentStatus::class];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return BelongsTo<ClassLevel, $this> */
    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }
}
