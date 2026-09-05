<?php

namespace App\Actions\Guardians;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LinkGuardianToStudent
{
    public function handle(Student $student, Guardian $guardian, string $relationship, bool $isPrimary): StudentGuardian
    {
        return DB::transaction(function () use ($student, $guardian, $relationship, $isPrimary): StudentGuardian {
            Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();

            if (StudentGuardian::query()->where('student_id', $student->id)->where('guardian_id', $guardian->id)->exists()) {
                throw ValidationException::withMessages([
                    'selectedGuardianId' => 'This guardian is already linked to the student.',
                ]);
            }

            if ($isPrimary) {
                StudentGuardian::query()->where('student_id', $student->id)->where('is_primary', true)->update(['is_primary' => false]);
            }

            return StudentGuardian::query()->create([
                'student_id' => $student->id,
                'guardian_id' => $guardian->id,
                'relationship' => $relationship,
                'is_primary' => $isPrimary,
            ]);
        }, attempts: 5);
    }
}
