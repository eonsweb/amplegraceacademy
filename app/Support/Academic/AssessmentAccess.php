<?php

namespace App\Support\Academic;

use App\Models\Assessment;
use App\Models\ClassSubject;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Builder;

class AssessmentAccess
{
    public static function allSubjects(User $user, bool $write = false): bool
    {
        return $user->can(Permissions::ASSESSMENTS_MANAGE_ALL)
            || (! $write && $user->can(Permissions::RESULTS_VIEW_ALL));
    }

    /** @return Builder<ClassSubject> */
    public static function assignments(User $user, bool $write = false): Builder
    {
        return ClassSubject::query()->when(! self::allSubjects($user, $write), fn (Builder $query): Builder => $query->where('staff_id', $user->id));
    }

    public static function context(User $user, int $yearId, int $classId, int $subjectId, bool $write = false): bool
    {
        return self::allSubjects($user, $write) || self::assignments($user, $write)
            ->where('academic_year_id', $yearId)->where('class_level_id', $classId)->where('subject_id', $subjectId)->exists();
    }

    /** @return Builder<Assessment> */
    public static function assessments(User $user): Builder
    {
        return Assessment::query()->when(! self::allSubjects($user), function (Builder $query) use ($user): void {
            $query->whereExists(self::assignments($user)->selectRaw('1')
                ->whereColumn('class_subjects.academic_year_id', 'assessments.academic_year_id')
                ->whereColumn('class_subjects.class_level_id', 'assessments.class_level_id')
                ->whereColumn('class_subjects.subject_id', 'assessments.subject_id'));
        });
    }
}
