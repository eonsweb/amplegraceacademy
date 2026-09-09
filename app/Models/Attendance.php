<?php

namespace App\Models;

use App\AttendanceStatus;
use App\EnrollmentStatus;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property AttendanceStatus $status */
#[Fillable(['enrollment_id', 'term_id', 'attendance_date', 'status', 'remark'])]
class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['attendance_date' => 'date', 'status' => AttendanceStatus::class];
    }

    /** @return Attribute<never, string> */
    protected function attendanceDate(): Attribute
    {
        return Attribute::make(set: fn ($value): string => Carbon::parse($value)->toDateString());
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return Builder<Enrollment> */
    public static function roster(int $yearId, int $classId, string $date): Builder
    {
        return Enrollment::query()->where('academic_year_id', $yearId)->where('class_level_id', $classId)
            ->whereDate('enrollment_date', '<=', $date)
            ->where(function (Builder $query) use ($date): void {
                $query->where('status', EnrollmentStatus::Active->value)
                    ->orWhereHas('attendances', fn (Builder $attendance): Builder => $attendance->where('attendance_date', $date));
            });
    }
}
