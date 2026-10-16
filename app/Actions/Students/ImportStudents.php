<?php

namespace App\Actions\Students;

use App\Events\StudentsImported;
use App\Models\StudentImport;
use App\Models\StudentImportRow;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\StudentImportReader;
use App\Support\StudentImportValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ImportStudents
{
    public function __construct(private StudentImportReader $reader, private StudentImportValidator $validator, private CreateStudent $creator) {}

    public function authorize(User $user): void
    {
        foreach ([Permissions::STUDENTS_IMPORT, Permissions::STUDENTS_CREATE, Permissions::STUDENTS_VIEW, Permissions::GUARDIANS_VIEW, Permissions::GUARDIANS_CREATE, Permissions::GUARDIANS_LINK_STUDENT] as $permission) {
            Gate::forUser($user)->authorize($permission);
        }
    }

    public function owned(User $user, string $id): StudentImport
    {
        $this->authorize($user);

        return StudentImport::query()->where('user_id', $user->id)->where('expires_at', '>', now())->findOrFail($id);
    }

    public function prepare(User $user, UploadedFile $file, string $enrollmentDate): StudentImport
    {
        $this->authorize($user);
        Validator::make(['enrollment_date' => $enrollmentDate], ['enrollment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']])->validate();
        abort_if(StudentImport::query()->where('user_id', $user->id)->where('expires_at', '>', now())->count() >= 10, 422, 'Remove an earlier import before uploading another. Maximum 10 active imports.');
        $rows = $this->reader->read($file);
        $results = $this->validator->validate($rows, $enrollmentDate);

        return DB::transaction(function () use ($user, $enrollmentDate, $rows, $results): StudentImport {
            $import = StudentImport::query()->create(['user_id' => $user->id, 'status' => 'preview', 'enrollment_date' => $enrollmentDate, 'expires_at' => now()->addDay()]);
            $records = [];
            foreach ($rows as $index => $payload) {
                $records[] = (new StudentImportRow([
                    'student_import_id' => $import->id, 'row_number' => $payload['_row'], 'payload' => $payload,
                    'status' => $results[$index]['status'], 'messages' => $results[$index]['messages'],
                ]))->getAttributes() + ['created_at' => now(), 'updated_at' => now()];
            }
            foreach (array_chunk($records, 100) as $chunk) {
                DB::table('student_import_rows')->insert($chunk);
            }

            return $import;
        });
    }

    /** @param array<string, string> $values */
    public function correct(User $user, string $id, int $rowId, array $values, bool $generate): void
    {
        $this->owned($user, $id);
        Validator::make(['values' => $values], [
            'values' => ['present', 'array:'.implode(',', StudentImportReader::HEADERS)],
            'values.*' => ['nullable', 'string', 'max:5000'],
        ])->validate();
        DB::transaction(function () use ($user, $id, $rowId, $values, $generate): void {
            $import = $this->owned($user, $id)->newQuery()->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($import->status === 'importing', 409, 'Wait for the current import to finish before editing.');
            $row = $import->rows()->whereKey($rowId)->lockForUpdate()->firstOrFail();
            abort_if($row->status === 'imported', 409, 'Imported students must be edited through student management.');
            $payload = $row->payload;
            foreach ($values as $header => $value) {
                $value = trim((string) $value);
                if ($value !== ($payload[$header] ?? '')) {
                    unset($payload['_errors'][$header]);
                }
                $payload[$header] = $value;
            }
            if (($payload['Admission Number'] ?? '') !== '') {
                unset($payload['_number_issue']);
            }
            $payload['_generate'] = $generate;
            $row->update(['payload' => $payload, 'generate_number' => $generate]);
            $import->update(['status' => 'preview']);
            $this->refreshPreview($import);
        });
    }

    private function refreshPreview(StudentImport $import): void
    {
        $rows = $import->rows()->orderBy('row_number')->get();
        $results = $this->validator->validate(array_values($rows->map(fn (StudentImportRow $row): array => $row->payload)->all()), $import->enrollment_date);
        foreach ($rows as $index => $row) {
            if ($row->status !== 'imported') {
                $row->fill(['status' => $results[$index]['status'], 'messages' => $results[$index]['messages']]);
                if ($row->isDirty()) {
                    $row->save();
                }
            }
        }
    }

    public function confirm(User $user, string $id): void
    {
        $this->owned($user, $id);
        DB::transaction(function () use ($user, $id): void {
            $import = $this->owned($user, $id)->newQuery()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($import->status === 'importing') {
                return;
            }
            abort_unless($import->status === 'preview', 409, 'This import has already finished.');
            $this->refreshPreview($import);
            $rows = $import->rows()->whereIn('status', ['ready', 'warning'])->get();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['import' => 'No rows are ready. Correct the rows requiring review first.']);
            }
            $reservations = [];
            foreach ($rows as $row) {
                if (! $row->generate_number) {
                    $parts = $this->validator->numberParts($row->payload['Admission Number'], $row->payload['Admission Date']);
                    if ($parts !== null && $parts['sequence'] > ($reservations[$parts['year']]['sequence'] ?? 0)) {
                        $reservations[$parts['year']] = ['sequence' => $parts['sequence'], 'number' => $row->payload['Admission Number'], 'date' => $row->payload['Admission Date']];
                    }
                }
            }
            ksort($reservations);
            foreach ($reservations as $reservation) {
                $this->creator->reserveImportNumber($reservation['number'], $reservation['date']);
            }
            $import->rows()->whereIn('status', ['ready', 'warning'])->update(['status' => 'pending']);
            $import->update(['status' => 'importing']);
        });
    }

    /** Process at most five students, each in an independent transaction. */
    public function process(User $user, string $id): void
    {
        $import = $this->owned($user, $id);
        if ($import->status !== 'importing') {
            return;
        }
        $ids = $import->rows()->where('status', 'pending')->orderBy('generate_number')->orderBy('row_number')->limit(5)->pluck('id');
        foreach ($ids as $rowId) {
            DB::transaction(function () use ($user, $id, $rowId): void {
                $this->lockImports();
                $batch = $this->owned($user, $id)->newQuery()->whereKey($id)->lockForUpdate()->firstOrFail();
                if ($batch->status !== 'importing') {
                    return;
                }
                $row = $batch->rows()->whereKey($rowId)->lockForUpdate()->firstOrFail();
                if ($row->status !== 'pending') {
                    return;
                }
                $result = $this->validator->validate([$row->payload], $batch->enrollment_date)[0];
                if (! in_array($result['status'], ['ready', 'warning'], true)) {
                    $row->update(['status' => $result['status'], 'messages' => $result['messages']]);

                    return;
                }
                try {
                    DB::transaction(function () use ($result, $row, $user, $id): void {
                        $student = $this->creator->handle($result['student'], $result['enrollment'], $result['guardians'], $result['number'], $result['student']['admission_date']);
                        $created = count(array_filter($result['guardians'], fn (array $guardian): bool => $guardian['guardian_id'] === null));
                        $row->update(['status' => 'imported', 'student_id' => $student->id,
                            'guardians_created' => $created, 'guardians_linked' => count($result['guardians']) - $created,
                            'messages' => ['Student, guardians and enrollment saved. Admission number: '.$student->admission_number]]);
                        StudentsImported::dispatch($user->id, $id, $student->id);
                    });
                } catch (ValidationException) {
                    $row->update(['status' => 'failed', 'messages' => ['Data changed during import. Nothing from this row was saved. Review the student and academic records, then edit this row to retry.']]);
                } catch (Throwable $exception) {
                    Log::error('Student import row failed', ['import_id' => $id, 'row' => $row->row_number, 'exception_type' => $exception::class]);
                    $row->update(['status' => 'failed', 'messages' => ['This row could not be saved. No partial records were kept. Ask an administrator to check the import reference, then edit this row to retry.']]);
                }
            }, 3);
        }
        DB::transaction(function () use ($user, $id): void {
            $batch = $this->owned($user, $id)->newQuery()->whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $batch->rows()->where('status', 'pending')->exists()) {
                $batch->update(['status' => 'completed']);
            }
        });
    }

    /** Serialize import identity checks across batches without a queue or Redis. */
    private function lockImports(): void
    {
        DB::table('admission_number_sequences')->insertOrIgnore([
            'key' => 'student_import', 'year' => 0, 'current_value' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('admission_number_sequences')->where('key', 'student_import')->where('year', 0)->lockForUpdate()->firstOrFail();
    }

    public function discard(User $user, string $id): void
    {
        $this->owned($user, $id);
        DB::transaction(function () use ($user, $id): void {
            $batch = $this->owned($user, $id)->newQuery()->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($batch->status === 'importing', 409, 'Finish the running import before removing its preview.');
            $batch->delete();
        });
    }
}
