<?php

namespace App\Support\Academic;

use App\Models\AcademicYear;
use App\Models\Term;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcademicContext
{
    private const CURRENT_YEAR_CACHE_KEY = 'academic.current-year-id';

    private const CURRENT_TERM_CACHE_KEY = 'academic.current-term-id';

    public function currentYearId(): ?int
    {
        return Cache::rememberForever(self::CURRENT_YEAR_CACHE_KEY, fn (): ?int => AcademicYear::query()->where('is_current', true)->value('id'));
    }

    public function currentTermId(): ?int
    {
        return Cache::rememberForever(self::CURRENT_TERM_CACHE_KEY, fn (): ?int => Term::query()->where('is_current', true)->value('id'));
    }

    public function setCurrentYear(AcademicYear $academicYear, bool $selectFirstTerm = false): void
    {
        DB::transaction(function () use ($academicYear, $selectFirstTerm): void {
            AcademicYear::query()->whereNot('id', $academicYear->id)->where('is_current', true)->update(['is_current' => null]);
            $academicYear->update(['is_current' => true]);
            Term::query()->where('is_current', true)->whereNot('academic_year_id', $academicYear->id)->update(['is_current' => null]);

            if ($selectFirstTerm) {
                Term::query()->where('is_current', true)->update(['is_current' => null]);
                Term::query()->whereBelongsTo($academicYear)->where('term_order', 1)->update(['is_current' => true]);
            }
        });

        $this->forget();
    }

    public function setCurrentTerm(Term $term): void
    {
        if (! AcademicYear::query()->whereKey($term->academic_year_id)->where('is_current', true)->exists()) {
            throw ValidationException::withMessages([
                'term' => 'The current term must belong to the current academic year.',
            ]);
        }

        DB::transaction(function () use ($term): void {
            Term::query()->whereNot('id', $term->id)->where('is_current', true)->update(['is_current' => null]);
            $term->update(['is_current' => true]);
        });

        $this->forget();
    }

    public function forget(): void
    {
        Cache::forget(self::CURRENT_YEAR_CACHE_KEY);
        Cache::forget(self::CURRENT_TERM_CACHE_KEY);
    }
}
