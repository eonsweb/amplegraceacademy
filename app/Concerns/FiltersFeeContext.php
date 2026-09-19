<?php

namespace App\Concerns;

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\Invoice;
use App\Models\Term;
use App\Support\Fees\FeeLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait FiltersFeeContext
{
    #[Url]
    public string $academicYearId = '';

    #[Url]
    public string $termId = '';

    #[Url]
    public string $classLevelId = '';

    #[Url]
    public string $search = '';

    public function updatedAcademicYearId(): void
    {
        $this->termId = '';
        $this->resetPage();
    }

    public function updatedTermId(): void
    {
        $this->resetPage();
    }

    public function updatedClassLevelId(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
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
        return Term::query()->when($this->academicYearId !== '', fn (Builder $q) => $q->where('academic_year_id', $this->academicYearId))->orderBy('term_order')->get(['id', 'name', 'academic_year_id']);
    }

    /** @return Collection<int, ClassLevel> */
    #[Computed]
    public function classes(): Collection
    {
        return ClassLevel::query()->orderBy('level_order')->get(['id', 'name']);
    }

    /** @return Builder<Invoice> */
    protected function invoiceQuery(): Builder
    {
        $search = trim($this->search);

        return FeeLedger::invoices()
            ->when($this->academicYearId !== '', fn (Builder $q) => $q->where('academic_year_id', $this->academicYearId))
            ->when($this->termId !== '', fn (Builder $q) => $q->where('term_id', $this->termId))
            ->when($this->classLevelId !== '', fn (Builder $q) => $q->where('class_level_id', $this->classLevelId))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('student_name', 'like', '%'.$search.'%')->orWhere('admission_number', 'like', '%'.$search.'%')->orWhere('invoice_number', 'like', '%'.$search.'%')));
    }
}
