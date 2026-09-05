<?php

namespace App\Actions\Students;

use App\Models\Guardian;
use App\Models\Student;
use App\Support\Settings\SystemSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateStudent
{
    private const SEQUENCE_KEY = 'student_admission';

    public function __construct(private SystemSettings $settings) {}

    /**
     * @param  array<string, mixed>  $studentData
     * @param  array<string, mixed>  $enrollmentData
     * @param  list<array{guardian_id: int|null, data: array<string, mixed>, relationship?: string, is_primary: bool}>  $guardianRows
     */
    public function handle(array $studentData, array $enrollmentData, array $guardianRows): Student
    {
        if ($guardianRows === [] || collect($guardianRows)->where('is_primary', true)->count() !== 1) {
            throw ValidationException::withMessages(['guardians' => 'Provide at least one guardian and select exactly one primary guardian.']);
        }

        $schoolInitials = $this->settings->schoolInitials();

        if ($schoolInitials === null) {
            throw ValidationException::withMessages([
                'admissionNumber' => 'School initials have not been configured. Please configure them in System Settings before admitting students.',
            ]);
        }

        unset($studentData['admission_number'], $studentData['age']);

        return DB::transaction(function () use ($studentData, $enrollmentData, $guardianRows, $schoolInitials): Student {
            $admissionYear = Carbon::now()->year;
            $now = Carbon::now();

            DB::table('admission_number_sequences')->insertOrIgnore([
                'key' => self::SEQUENCE_KEY,
                'year' => $admissionYear,
                'current_value' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $sequence = DB::table('admission_number_sequences')
                ->where('key', self::SEQUENCE_KEY)
                ->where('year', $admissionYear)
                ->lockForUpdate()
                ->firstOrFail(['id', 'current_value']);
            $nextValue = (int) $sequence->current_value + 1;

            DB::table('admission_number_sequences')->where('id', $sequence->id)->update([
                'current_value' => $nextValue,
                'updated_at' => $now,
            ]);

            $student = new Student;
            $student->admission_number = sprintf('%s/%d/%04d', $schoolInitials, $admissionYear, $nextValue);
            $student->fill($studentData)->save();

            foreach ($guardianRows as $guardianRow) {
                $guardian = $guardianRow['guardian_id'] === null
                    ? Guardian::query()->create($guardianRow['data'])
                    : Guardian::query()->findOrFail($guardianRow['guardian_id']);

                $student->guardians()->attach($guardian, [
                    'relationship' => $guardianRow['relationship'] ?? $guardianRow['data']['relationship'] ?? $guardian->relationship,
                    'is_primary' => $guardianRow['is_primary'],
                ]);
            }

            $student->enrollments()->create($enrollmentData);

            return $student;
        }, attempts: 5);
    }
}
