<?php

namespace App\Actions\Students;

use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateStudent
{
    /**
     * @param  array<string, mixed>  $studentData
     * @param  array<string, mixed>  $enrollmentData
     * @param  list<array{guardian_id: int|null, data: array<string, mixed>, is_primary: bool}>  $guardianRows
     */
    public function handle(array $studentData, array $enrollmentData, array $guardianRows): Student
    {
        if ($guardianRows === [] || collect($guardianRows)->where('is_primary', true)->count() !== 1) {
            throw ValidationException::withMessages(['guardians' => 'Provide at least one guardian and select exactly one primary guardian.']);
        }

        return DB::transaction(function () use ($studentData, $enrollmentData, $guardianRows): Student {
            $student = Student::query()->create($studentData);

            foreach ($guardianRows as $guardianRow) {
                $guardian = $guardianRow['guardian_id'] === null
                    ? Guardian::query()->create($guardianRow['data'])
                    : Guardian::query()->findOrFail($guardianRow['guardian_id']);

                $student->guardians()->attach($guardian, ['is_primary' => $guardianRow['is_primary']]);
            }

            $student->enrollments()->create($enrollmentData);

            return $student;
        });
    }
}
