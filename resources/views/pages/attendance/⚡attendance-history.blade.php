<?php

use App\AttendanceStatus;
use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\ClassLevel;
use App\Models\ClassSubject;
use App\Models\Term;
use App\Policies\AttendancePolicy;
use App\Support\Settings\SystemSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Attendance History')] class extends Component {
    use WithPagination;

    #[Url] public string $academicYearId = '';
    #[Url] public string $termId = '';
    #[Url] public string $classLevelId = '';
    #[Url] public string $search = '';
    #[Url] public string $dateFrom = '';
    #[Url] public string $dateTo = '';
    #[Url] public string $statusFilter = '';

    public function boot(): void
    {
        Gate::authorize('viewAny', Attendance::class);
    }

    public function updated(string $property): void
    {
        if ($property === 'academicYearId') {
            $this->termId = '';
            $this->classLevelId = '';
        }
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('academicYearId', 'termId', 'classLevelId', 'search', 'dateFrom', 'dateTo', 'statusFilter');
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Attendance> */
    #[Computed]
    public function attendances(): LengthAwarePaginator
    {
        return Attendance::query()
            ->with(['enrollment.student:id,admission_number,first_name,middle_name,last_name', 'enrollment.classLevel:id,name', 'enrollment.academicYear:id,name', 'term:id,name'])
            ->whereHas('enrollment', function (Builder $query): void {
                $query->when($this->academicYearId !== '', fn (Builder $query): Builder => $query->where('academic_year_id', $this->academicYearId))
                    ->when($this->classLevelId !== '', fn (Builder $query): Builder => $query->where('class_level_id', $this->classLevelId));
                if (AttendancePolicy::requiresAssignment(auth()->user())) {
                    $query->whereExists(ClassSubject::query()->selectRaw('1')->whereColumn('class_subjects.class_level_id', 'enrollments.class_level_id')->whereColumn('class_subjects.academic_year_id', 'enrollments.academic_year_id')->where('staff_id', auth()->id()));
                }
            })
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $query->whereHas('enrollment.student', function (Builder $student): void {
                    foreach (preg_split('/\s+/', trim($this->search)) as $word) {
                        $student->where(fn (Builder $part): Builder => $part->where('first_name', 'like', '%'.$word.'%')->orWhere('middle_name', 'like', '%'.$word.'%')->orWhere('last_name', 'like', '%'.$word.'%')->orWhere('admission_number', 'like', '%'.$word.'%'));
                    }
                });
            })
            ->when($this->termId !== '', fn (Builder $query): Builder => $query->where('term_id', $this->termId))
            ->when($this->dateFrom !== '', fn (Builder $query): Builder => $query->where('attendance_date', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn (Builder $query): Builder => $query->where('attendance_date', '<=', $this->dateTo))
            ->when($this->statusFilter !== '', fn (Builder $query): Builder => $query->where('status', $this->statusFilter))
            ->orderByDesc('attendance_date')->orderByDesc('id')
            ->paginate(app(SystemSettings::class)->recordsPerPage());
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
        return Term::query()->when($this->academicYearId !== '', fn ($query) => $query->where('academic_year_id', $this->academicYearId))->orderBy('term_order')->get(['id', 'name', 'academic_year_id']);
    }

    /** @return Collection<int, ClassLevel> */
    #[Computed]
    public function classes(): Collection
    {
        return ClassLevel::query()->when(AttendancePolicy::requiresAssignment(auth()->user()), fn ($query) => $query->whereIn('id', ClassSubject::query()->select('class_level_id')->where('staff_id', auth()->id())->when($this->academicYearId !== '', fn ($query) => $query->where('academic_year_id', $this->academicYearId))))->orderBy('level_order')->get(['id', 'name']);
    }
};
?>

<div class="grid gap-5">
    <div class="flex flex-wrap items-center justify-between gap-3"><flux:heading size="xl">Attendance History</flux:heading><flux:button :href="route('attendance.index')" wire:navigate>Class Attendance</flux:button></div>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <flux:select label="Academic Year" wire:model.live="academicYearId"><option value="">All years</option>@foreach($this->years as $year)<option wire:key="history-year-{{ $year->id }}" value="{{ $year->id }}">{{ $year->name }}</option>@endforeach</flux:select>
        <flux:select label="Term" wire:model.live="termId"><option value="">All terms</option>@foreach($this->terms as $term)<option wire:key="history-term-{{ $term->id }}" value="{{ $term->id }}">{{ $term->name }} ({{ $this->years->firstWhere('id', $term->academic_year_id)?->name }})</option>@endforeach</flux:select>
        <flux:select label="Class" wire:model.live="classLevelId"><option value="">All classes</option>@foreach($this->classes as $class)<option wire:key="history-class-{{ $class->id }}" value="{{ $class->id }}">{{ $class->name }}</option>@endforeach</flux:select>
        <flux:select label="Status" wire:model.live="statusFilter"><option value="">All statuses</option>@foreach(AttendanceStatus::cases() as $status)<option wire:key="history-status-{{ $status->value }}" value="{{ $status->value }}">{{ $status->label() }}</option>@endforeach</flux:select>
        <flux:input label="Student" wire:model.live.debounce.400ms="search" placeholder="Name or admission number" />
        <flux:input label="From date" type="date" wire:model.live="dateFrom" />
        <flux:input label="To date" type="date" wire:model.live="dateTo" />
        <div class="flex items-end"><flux:button wire:click="resetFilters">Clear filters</flux:button></div>
    </div>
    <div wire:loading role="status" class="text-sm text-zinc-500">Loading history…</div>
    <x-app.panel title="Attendance records">
        <div class="divide-y divide-zinc-200 dark:divide-zinc-800">
            @forelse($this->attendances as $attendance)
                <article wire:key="history-{{ $attendance->id }}" class="grid gap-3 p-4 md:grid-cols-5 md:items-center">
                    <div><p class="font-semibold">{{ $attendance->enrollment->student->fullName() }}</p><p class="font-mono text-xs text-zinc-500">{{ $attendance->enrollment->student->admission_number }}</p></div>
                    <div><p>{{ $attendance->enrollment->classLevel->name }}</p><p class="text-xs text-zinc-500">{{ $attendance->enrollment->academicYear->name }} · {{ $attendance->term->name }}</p></div>
                    <time datetime="{{ $attendance->attendance_date->toDateString() }}">{{ $attendance->attendance_date->toDateString() }}</time>
                    <div><flux:badge>{{ $attendance->status->label() }}</flux:badge></div>
                    <p class="break-words text-sm text-zinc-500">{{ $attendance->remark ?? 'No remark' }}</p>
                </article>
            @empty<div class="p-10 text-center text-zinc-500">No attendance records match these filters.</div>@endforelse
        </div>
        <div class="p-4">{{ $this->attendances->links() }}</div>
    </x-app.panel>
</div>
