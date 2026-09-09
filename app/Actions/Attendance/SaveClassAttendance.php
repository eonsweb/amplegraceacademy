<?php

namespace App\Actions\Attendance;

use App\AttendanceStatus;
use App\Models\Attendance;
use App\Models\ClassLevel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveClassAttendance
{
    /** @return array<string, mixed> */
    public static function selectionRules(int|string $yearId): array
    {
        return [
            'academicYearId' => ['required', 'integer', 'exists:academic_years,id'],
            'termId' => ['required', 'integer', Rule::exists('terms', 'id')->where('academic_year_id', $yearId)],
            'classLevelId' => ['required', 'integer', 'exists:class_levels,id'],
            'attendanceDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    /** @param array<string, mixed> $data */
    public function handle(User $user, array $data): void
    {
        $gate = Gate::forUser($user);
        $gate->authorize('viewAny', Attendance::class);
        $validated = Validator::make($data, [
            ...self::selectionRules($data['academicYearId'] ?? ''),
            'rows' => ['required', 'array', 'min:1'],
            'rows.*' => ['required', 'array:enrollment_id,status,remark'],
            'rows.*.enrollment_id' => ['required', 'integer', 'distinct'],
            'rows.*.status' => ['required', Rule::enum(AttendanceStatus::class)],
            'rows.*.remark' => ['nullable', 'string', 'max:500'],
        ])->validate();
        $rows = array_values($validated['rows']);

        $yearId = (int) $validated['academicYearId'];
        $classId = (int) $validated['classLevelId'];
        $date = $validated['attendanceDate'];
        $termId = (int) $validated['termId'];
        $gate->authorize('viewClass', [Attendance::class, $yearId, $classId]);

        DB::transaction(function () use ($gate, $rows, $yearId, $classId, $date, $termId): void {
            ClassLevel::query()->whereKey($classId)->lockForUpdate()->firstOrFail();
            $enrollments = Attendance::roster($yearId, $classId, $date)->orderBy('id')->lockForUpdate()->pluck('id');
            $submitted = collect($rows)->pluck('enrollment_id')->map(fn ($id): int => (int) $id);
            if ($submitted->diff($enrollments)->isNotEmpty() || $enrollments->diff($submitted)->isNotEmpty()) {
                throw ValidationException::withMessages(['rows' => 'The class roster has changed or contains invalid enrollments. Reload the class before saving.']);
            }

            $existing = Attendance::query()->whereIn('enrollment_id', $enrollments)->where('attendance_date', $date)->lockForUpdate()->get()->keyBy('enrollment_id');
            if ($existing->contains(fn (Attendance $attendance): bool => $attendance->term_id !== $termId)) {
                throw ValidationException::withMessages(['termId' => 'Attendance for this date is already recorded under another term. Select that term.']);
            }

            $changes = [];
            foreach ($rows as $row) {
                $previous = $existing->get((int) $row['enrollment_id']);
                $remark = isset($row['remark']) && trim($row['remark']) !== '' ? trim($row['remark']) : null;
                if ($previous !== null && $previous->status->value === $row['status'] && $previous->remark === $remark) {
                    continue;
                }
                $gate->authorize($previous === null ? 'create' : 'update', Attendance::class);
                $changes[] = ['enrollment_id' => (int) $row['enrollment_id'], 'term_id' => $termId, 'attendance_date' => $date, 'status' => $row['status'], 'remark' => $remark];
            }

            foreach (array_chunk($changes, 250) as $chunk) {
                Attendance::query()->upsert($chunk, ['enrollment_id', 'attendance_date'], ['status', 'remark', 'updated_at']);
            }
        }, 3);
    }
}
