<?php

namespace App\Concerns;

use App\Models\AcademicYear;
use App\Models\Term;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait FiltersFinancePeriod
{
    /** @var array<string, string>|null */
    protected ?array $resolvedPeriodFilters = null;

    #[Url]
    public string $academicYearId = '';

    #[Url]
    public string $termId = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    public function updatedAcademicYearId(): void
    {
        $this->termId = '';
        unset($this->terms);
    }

    /** @return Collection<int, AcademicYear> */
    #[Computed]
    public function years(): Collection
    {
        return AcademicYear::query()->orderByDesc('name')->get(['id', 'name']);
    }

    /** @return Collection<int, Term> */
    #[Computed]
    public function terms(): Collection
    {
        return Term::query()->where('academic_year_id', $this->academicYearId ?: 0)->orderBy('term_order')->get(['id', 'name']);
    }

    /** @return array<string, string> */
    protected function periodFilters(): array
    {
        if ($this->resolvedPeriodFilters !== null) {
            return $this->resolvedPeriodFilters;
        }

        $validator = Validator::make([
            'academicYearId' => $this->academicYearId ?: null, 'termId' => $this->termId ?: null,
            'dateFrom' => $this->dateFrom ?: null, 'dateTo' => $this->dateTo ?: null,
        ], [
            'academicYearId' => ['nullable', 'integer', Rule::exists('academic_years', 'id')],
            'termId' => ['nullable', 'integer', Rule::exists('terms', 'id')->where('academic_year_id', $this->academicYearId ?: null)],
            'dateFrom' => ['nullable', 'date_format:Y-m-d'],
            'dateTo' => ['nullable', 'date_format:Y-m-d', ...($this->dateFrom !== '' ? ['after_or_equal:dateFrom'] : [])],
        ]);
        $this->resetValidation(['academicYearId', 'termId', 'dateFrom', 'dateTo']);
        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return $this->resolvedPeriodFilters = ['invalid' => '1'];
        }

        return $this->resolvedPeriodFilters = ['academic_year_id' => $this->academicYearId, 'term_id' => $this->termId,
            'date_from' => $this->dateFrom, 'date_to' => $this->dateTo];
    }
}
