<?php

namespace App\Actions\Students;

use App\Models\Guardian;
use App\Models\Student;
use App\Support\Settings\SystemSettings;
use App\Support\StudentImportValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateStudent
{
    private const SEQUENCE_KEY = 'student_admission';

    public function __construct(private SystemSettings $settings) {}

    public function reserveImportNumber(string $number, string $admissionDate): void
    {
        $parts = app(StudentImportValidator::class)->numberParts($number, $admissionDate);
        if ($parts === null) {
            throw ValidationException::withMessages(['admissionNumber' => 'Invalid imported admission number.']);
        }
        DB::transaction(function () use ($parts): void {
            DB::table('admission_number_sequences')->insertOrIgnore(['key' => self::SEQUENCE_KEY, 'year' => $parts['year'], 'current_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $sequence = DB::table('admission_number_sequences')->where('key', self::SEQUENCE_KEY)->where('year', $parts['year'])->lockForUpdate()->firstOrFail();
            DB::table('admission_number_sequences')->where('id', $sequence->id)->update(['current_value' => max((int) $sequence->current_value, $parts['sequence']), 'updated_at' => now()]);
        }, 5);
    }

    /**
     * @param  array<string, mixed>  $studentData
     * @param  array<string, mixed>  $enrollmentData
     * @param  list<array{guardian_id: int|null, data: array<string, mixed>, relationship?: string, is_primary: bool}>  $guardianRows
     */
    public function handle(array $studentData, array $enrollmentData, array $guardianRows, ?string $importNumber = null, ?string $originalAdmissionDate = null): Student
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

        if ($originalAdmissionDate !== null) {
            Validator::make(['admission_date' => $originalAdmissionDate], ['admission_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']])->validate();
        }
        if ($importNumber !== null && ($originalAdmissionDate === null || app(StudentImportValidator::class)->numberParts($importNumber, $originalAdmissionDate) === null)) {
            throw ValidationException::withMessages(['admissionNumber' => 'A preserved number must match the school initials and original admission year.']);
        }

        return DB::transaction(function () use ($studentData, $enrollmentData, $guardianRows, $schoolInitials, $importNumber, $originalAdmissionDate): Student {
            $admissionYear = $originalAdmissionDate === null ? Carbon::now()->year : (int) substr($originalAdmissionDate, 0, 4);
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
            if ($importNumber !== null) {
                $parts = app(StudentImportValidator::class)->numberParts($importNumber, $originalAdmissionDate);
                if (Student::query()->whereIn('admission_number', [$importNumber, str_replace('-', '/', $importNumber), str_replace('/', '-', $importNumber)])->exists()) {
                    throw ValidationException::withMessages(['admissionNumber' => 'This admission number is already in use.']);
                }
                $nextValue = max((int) $sequence->current_value, $parts['sequence']);
            } else {
                $nextValue = (int) $sequence->current_value + 1;
                while (Student::query()->whereIn('admission_number', [sprintf('%s/%d/%04d', $schoolInitials, $admissionYear, $nextValue), sprintf('%s-%d-%04d', $schoolInitials, $admissionYear, $nextValue)])->exists()) {
                    $nextValue++;
                }
            }

            DB::table('admission_number_sequences')->where('id', $sequence->id)->update([
                'current_value' => $nextValue,
                'updated_at' => $now,
            ]);

            $student = new Student;
            $student->admission_number = $importNumber ?? sprintf('%s/%d/%04d', $schoolInitials, $admissionYear, $nextValue);
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
