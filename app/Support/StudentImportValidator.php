<?php

namespace App\Support;

use App\EnrollmentStatus;
use App\Gender;
use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\Guardian;
use App\Models\Student;
use App\Support\Settings\SystemSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StudentImportValidator
{
    public function __construct(private SystemSettings $settings) {}

    /** @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function validate(array $rows, string $enrollmentDate): array
    {
        $years = AcademicYear::query()->get(['id', 'name'])->groupBy(fn ($year): string => self::key($year->name));
        $classes = ClassLevel::query()->where('is_active', true)->get(['id', 'name'])->groupBy(fn ($class): string => self::key($class->name));
        $lastNames = collect($rows)->pluck('Last Name')->map(fn ($name): string => self::key((string) $name))->unique()->all();
        $numbers = [];
        $guardianNames = [];
        foreach ($rows as $row) {
            $number = (string) ($row['Admission Number'] ?? '');
            $numbers[] = $number;
            $numbers[] = str_replace('-', '/', $number);
            $numbers[] = str_replace('/', '-', $number);
            foreach ([1, 2] as $index) {
                $guardianNames[] = self::key((string) ($row["Guardian $index Last Name"] ?? ''));
            }
        }
        $students = Student::query()->where(function ($query) use ($lastNames, $numbers): void {
            $query->whereIn('admission_number', $numbers)->orWhereIn(DB::raw("LOWER(TRIM(REGEXP_REPLACE(last_name, '[[:space:]]+', ' ')))"), $lastNames);
        })->limit(5001)->get(['id', 'admission_number', 'first_name', 'middle_name', 'last_name', 'date_of_birth']);
        $guardians = Guardian::query()->whereIn(DB::raw("LOWER(TRIM(REGEXP_REPLACE(last_name, '[[:space:]]+', ' ')))"), array_unique($guardianNames))
            ->limit(5001)->get(['id', 'first_name', 'middle_name', 'last_name', 'phone', 'address']);
        $numberCounts = [];
        $nameCounts = [];
        foreach ($rows as $row) {
            $numberKey = self::numberKey((string) ($row['Admission Number'] ?? ''));
            if ($numberKey !== '') {
                $numberCounts[$numberKey] = ($numberCounts[$numberKey] ?? 0) + 1;
            }
            $nameKey = self::studentKey($row);
            $nameCounts[$nameKey] = ($nameCounts[$nameKey] ?? 0) + 1;
        }
        $results = [];
        foreach ($rows as $row) {
            $messages = array_values($row['_errors'] ?? []);
            $warnings = [];
            $status = $messages === [] ? 'ready' : 'error';
            $data = array_fill_keys(StudentImportReader::HEADERS, '');
            foreach (StudentImportReader::HEADERS as $header) {
                $data[$header] = trim((string) ($row[$header] ?? ''));
            }
            foreach (['First Name', 'Middle Name', 'Last Name'] as $header) {
                $data[$header] = Str::squish($data[$header]);
            }
            $data['Gender'] = strtolower($data['Gender']);
            $data['Enrollment Status'] = strtolower($data['Enrollment Status']);
            $rules = [
                'First Name' => ['required', 'string', 'max:255'],
                'Middle Name' => ['nullable', 'string', 'max:255'],
                'Last Name' => ['required', 'string', 'max:255'],
                'Gender' => ['required', Rule::enum(Gender::class)],
                'Date of Birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
                'Admission Date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'Academic Year' => ['required', 'string', 'max:255'],
                'Class Level' => ['required', 'string', 'max:255'],
                'Enrollment Status' => ['required', Rule::enum(EnrollmentStatus::class)],
                'Student Address' => ['nullable', 'string', 'max:2000'],
                'Religion' => ['nullable', 'string', 'max:120'],
                'Medical Notes' => ['nullable', 'string', 'max:5000'],
            ];
            $validation = Validator::make($data, $rules);
            $messages = [...$messages, ...$validation->errors()->all()];
            if ($data['Date of Birth'] === '') {
                $warnings[] = 'Date of birth is optional and will remain empty.';
            }
            if (! $validation->errors()->has('Admission Date') && $data['Admission Date'] > $enrollmentDate) {
                $messages[] = 'Admission date cannot be after the enrollment date chosen for this import.';
            }
            if (! $validation->errors()->hasAny(['Date of Birth', 'Admission Date']) && $data['Date of Birth'] !== '' && $data['Date of Birth'] > $data['Admission Date']) {
                $messages[] = 'Date of birth cannot be after admission date.';
            }
            $year = $years->get(self::key($data['Academic Year']));
            $class = $classes->get(self::key($data['Class Level']));
            if ($year === null || $year->count() !== 1) {
                $messages[] = 'Academic year must match exactly one configured year. Create missing academic setup separately.';
            }
            if ($class === null || $class->count() !== 1) {
                $messages[] = 'Class level must match exactly one active configured class.';
            }
            $number = $data['Admission Number'];
            $numberKey = self::numberKey($number);
            $matches = $students->filter(fn (Student $student): bool => self::numberKey($student->admission_number) === $numberKey && $numberKey !== '');
            $generate = (bool) ($row['_generate'] ?? false);
            $validNumber = $this->numberParts($number, $data['Admission Date']);
            if ($matches->isNotEmpty()) {
                $same = $matches->count() === 1 && self::key($matches->first()->first_name.' '.$matches->first()->middle_name.' '.$matches->first()->last_name) === self::studentKey($data)
                    && ($data['Date of Birth'] === '' || $matches->first()->date_of_birth?->toDateString() === $data['Date of Birth']);
                $status = $same ? 'exists' : 'review';
                $messages[] = $same ? 'Already exists: existing student and enrollments will not be changed.' : 'Admission number belongs to a different student candidate. Review the existing record; do not generate a replacement.';
            } elseif ($validNumber === null && ! $generate) {
                $messages[] = $row['_number_issue'] ?? 'Admission number is missing or incompatible with school initials and original admission year. Correct it or explicitly request generation.';
            } elseif ($generate && $number !== '') {
                $messages[] = 'A supplied number must not be replaced silently. Clear it explicitly before requesting generation.';
            }
            if (($numberCounts[$numberKey] ?? 0) > 1) {
                $messages[] = 'Duplicate admission number within this workbook. Correct both rows before importing.';
                $status = 'review';
            }
            if (($nameCounts[self::studentKey($data)] ?? 0) > 1) {
                $messages[] = 'Repeated full name within this workbook requires review. Do not merge students automatically.';
                $status = 'review';
            }
            if ($matches->isEmpty() && $students->contains(fn (Student $student): bool => self::key($student->first_name.' '.$student->middle_name.' '.$student->last_name) === self::studentKey($data)
                && ($data['Date of Birth'] === '' || $student->date_of_birth === null || $student->date_of_birth->toDateString() === $data['Date of Birth']))) {
                $messages[] = 'Possible existing student with the same name and compatible birth date. Review the student directory; this row will not be merged.';
            }
            $guardianRows = [];
            $guardianIds = [];
            foreach ([1, 2] as $index) {
                $guardian = ['first_name' => '', 'last_name' => '', 'relationship' => '', 'phone' => '', 'address' => ''];
                foreach (['First Name' => 'first_name', 'Last Name' => 'last_name', 'Relationship' => 'relationship', 'Phone' => 'phone', 'Address' => 'address'] as $label => $field) {
                    $guardian[$field] = Str::squish($data["Guardian $index $label"]);
                }
                if (count(array_filter($guardian)) === 0) {
                    continue;
                }
                $guardian['phone'] = self::phone($guardian['phone']);
                $errors = Validator::make($guardian, [
                    'first_name' => ['required', 'string', 'max:255'], 'last_name' => ['required', 'string', 'max:255'],
                    'relationship' => ['required', 'string', 'max:80'], 'phone' => ['required', 'regex:/^\\+?[0-9]{7,15}$/D', 'max:50'],
                    'address' => ['nullable', 'string', 'max:2000'],
                ])->errors()->all();
                foreach ($errors as $error) {
                    $messages[] = "Guardian $index: ".$error;
                }
                $sameNames = $guardians->filter(fn (Guardian $existing): bool => self::key($existing->first_name) === self::key($guardian['first_name'])
                    && self::key($existing->last_name) === self::key($guardian['last_name']));
                $exact = $sameNames->filter(fn (Guardian $existing): bool => self::phone($existing->phone) === $guardian['phone'] && blank($existing->middle_name));
                $guardianId = null;
                if ($sameNames->isNotEmpty()) {
                    if ($exact->count() !== 1 || ($guardian['address'] !== '' && self::key((string) $exact->first()?->address) !== self::key($guardian['address']))) {
                        $messages[] = "Guardian $index: ambiguous identity or conflicting contact details. Correct the data or resolve the guardian record separately.";
                    } else {
                        $guardianId = (int) $exact->first()->id;
                        $warnings[] = "Guardian $index: existing guardian will be linked without changing contact details.";
                    }
                }
                $identity = self::key($guardian['first_name'].' '.$guardian['last_name']).'|'.$guardian['phone'];
                if (in_array($identity, $guardianIds, true)) {
                    $messages[] = 'Both guardian entries identify the same person. Keep a single guardian entry.';
                }
                $guardianIds[] = $identity;
                $guardianRows[] = ['guardian_id' => $guardianId, 'data' => $guardian, 'relationship' => $guardian['relationship'], 'is_primary' => $guardianRows === []];
            }
            if ($guardianRows === []) {
                $messages[] = 'Provide at least one complete guardian with first name, last name, relationship and phone.';
            }
            if ($students->count() > 5000 || $guardians->count() > 5000) {
                $messages[] = 'Too many matching records for safe automatic matching. Split the file or review these records manually.';
            }
            if ($this->settings->schoolInitials() === null) {
                $messages[] = 'Configure school initials in System Settings before importing.';
            }
            if ($status !== 'exists' && $status !== 'error') {
                $status = $messages !== [] ? 'review' : ($warnings !== [] ? 'warning' : 'ready');
            }
            $results[] = [
                'status' => $status, 'messages' => array_values(array_unique([...$messages, ...$warnings])),
                'student' => [
                    'first_name' => $data['First Name'], 'middle_name' => $data['Middle Name'] ?: null, 'last_name' => $data['Last Name'],
                    'gender' => $data['Gender'], 'date_of_birth' => $data['Date of Birth'] ?: null, 'status' => 'active',
                    'admission_date' => $data['Admission Date'], 'address' => $data['Student Address'] ?: null,
                    'religion' => $data['Religion'] ?: null, 'medical_notes' => $data['Medical Notes'] ?: null,
                ],
                'enrollment' => ['academic_year_id' => $year?->first()?->id, 'class_level_id' => $class?->first()?->id,
                    'enrollment_date' => $enrollmentDate, 'status' => $data['Enrollment Status']],
                'guardians' => $guardianRows, 'number' => $generate ? null : $number,
                'admission_year' => (int) substr($data['Admission Date'], 0, 4),
            ];
        }

        return $results;
    }

    /** @return array{year: int, sequence: int}|null */
    public function numberParts(string $number, string $admissionDate): ?array
    {
        $initials = $this->settings->schoolInitials();
        if ($initials === null || ! preg_match('/^'.preg_quote($initials, '/').'([-\\/])(\\d{4})\\1(\\d{4,9})$/D', $number, $parts)
            || $parts[2] !== substr($admissionDate, 0, 4) || (int) $parts[3] < 1
            || $parts[3] !== sprintf('%04d', (int) $parts[3])) {
            return null;
        }

        return ['year' => (int) $parts[2], 'sequence' => (int) $parts[3]];
    }

    public static function key(string $value): string
    {
        return mb_strtolower(Str::squish($value));
    }

    public static function phone(string $value): string
    {
        $value = preg_replace('/[\\s().-]+/', '', $value) ?? $value;
        if (preg_match('/^(?:\\+233|00233|233)(\\d{9})$/D', $value, $parts)) {
            return '0'.$parts[1];
        }
        if (preg_match('/^\\d{9}$/D', $value)) {
            return '0'.$value;
        }

        return $value;
    }

    public static function numberKey(string $value): string
    {
        return strtoupper(str_replace('-', '/', trim($value)));
    }

    /** @param array<string, mixed> $row */
    private static function studentKey(array $row): string
    {
        return self::key(($row['First Name'] ?? '').' '.($row['Middle Name'] ?? '').' '.($row['Last Name'] ?? ''));
    }
}
