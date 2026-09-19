<?php

namespace App\Models;

use App\AssessmentStatus;
use App\AssessmentType;
use Database\Factories\AssessmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * @property AssessmentType $type
 * @property AssessmentStatus $status
 */
#[Fillable(['academic_year_id', 'term_id', 'class_level_id', 'subject_id', 'name', 'type', 'maximum_score', 'assessment_date', 'status'])]
class Assessment extends Model
{
    /** @use HasFactory<AssessmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['maximum_score' => 'decimal:2', 'assessment_date' => 'date', 'type' => AssessmentType::class, 'status' => AssessmentStatus::class];
    }

    protected static function booted(): void
    {
        static::updating(function (Assessment $assessment): void {
            if ($assessment->isDirty(['academic_year_id', 'term_id', 'class_level_id', 'subject_id', 'maximum_score', 'assessment_date']) && $assessment->scores()->exists()) {
                throw ValidationException::withMessages(['assessment' => 'Academic context, date and maximum score cannot change after scores have been saved.']);
            }
        });
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<ClassLevel, $this> */
    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return HasMany<AssessmentScore, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(AssessmentScore::class);
    }

    /** @return Builder<Enrollment> */
    public function roster(): Builder
    {
        return Enrollment::query()->where('academic_year_id', $this->academic_year_id)->where('class_level_id', $this->class_level_id)
            ->when($this->assessment_date !== null, fn (Builder $query): Builder => $query->whereDate('enrollment_date', '<=', $this->assessment_date));
    }
}
