<?php

use App\Gender;
use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\Student;
use App\StudentStatus;
use App\Support\Authorization\Permissions;
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

new #[Title('Students')] class extends Component {
    use WithPagination;

    #[Url] public string $search = '';
    #[Url] public string $academicYearFilter = '';
    #[Url] public string $classLevelFilter = '';
    #[Url] public string $genderFilter = '';
    #[Url] public string $statusFilter = '';
    public int $recordsPerPage = 25;

    public function mount(SystemSettings $settings): void
    {
        Gate::authorize(Permissions::STUDENTS_VIEW);
        $this->recordsPerPage = $settings->recordsPerPage();
        $this->academicYearFilter = $this->academicYearFilter ?: (string) (AcademicYear::query()->where('is_current', true)->value('id') ?? '');
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedAcademicYearFilter(): void { $this->resetPage(); }
    public function updatedClassLevelFilter(): void { $this->resetPage(); }
    public function updatedGenderFilter(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->reset('search', 'academicYearFilter', 'classLevelFilter', 'genderFilter', 'statusFilter');
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Student> */
    #[Computed]
    public function students(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Student::query()
            ->select(['id', 'admission_number', 'first_name', 'middle_name', 'last_name', 'gender', 'photo', 'status'])
            ->with([
                'currentEnrollment' => fn ($query) => $query->select(['enrollments.id', 'enrollments.student_id', 'enrollments.academic_year_id', 'enrollments.class_level_id', 'enrollments.enrollment_date', 'enrollments.status']),
                'currentEnrollment.academicYear:id,name',
                'currentEnrollment.classLevel:id,name',
                'primaryGuardians:id,first_name,middle_name,last_name,phone,email',
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->where('admission_number', 'like', '%'.$search.'%')->orWhere('first_name', 'like', '%'.$search.'%')->orWhere('middle_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%');
                });
            })
            ->when($this->academicYearFilter !== '' || $this->classLevelFilter !== '', function (Builder $query): void {
                $query->whereHas('enrollments', function (Builder $enrollmentQuery): void {
                    $enrollmentQuery
                        ->when($this->academicYearFilter !== '', fn (Builder $query): Builder => $query->where('academic_year_id', $this->academicYearFilter))
                        ->when($this->classLevelFilter !== '', fn (Builder $query): Builder => $query->where('class_level_id', $this->classLevelFilter));
                });
            })
            ->when($this->genderFilter !== '', fn (Builder $query): Builder => $query->where('gender', $this->genderFilter))
            ->when($this->statusFilter !== '', fn (Builder $query): Builder => $query->where('status', $this->statusFilter))
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')->paginate($this->recordsPerPage);
    }

    /** @return Collection<int, AcademicYear> */
    #[Computed]
    public function academicYears(): Collection
    {
        return AcademicYear::query()->select(['id', 'name'])->orderByDesc('name')->get();
    }

    /** @return Collection<int, ClassLevel> */
    #[Computed]
    public function classLevels(): Collection
    {
        return ClassLevel::query()->select(['id', 'name'])->orderBy('level_order')->orderBy('id')->get();
    }
};
?>

<div class="grid gap-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div><flux:heading size="xl">Students</flux:heading><flux:text class="mt-1">Search student records and review current placement and guardian contacts.</flux:text></div>
        @can(Permissions::STUDENTS_CREATE)<flux:button :href="route('students.create')" wire:navigate variant="primary" icon="plus">Add Student</flux:button>@endcan
    </div>
    <x-app.panel title="Student directory">
        <div class="grid gap-3 border-b border-zinc-200 p-4 dark:border-zinc-800 md:grid-cols-2 xl:grid-cols-6">
            <flux:input class="xl:col-span-2" wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Name or admission number" />
            <flux:select wire:model.live="academicYearFilter" aria-label="Academic year"><option value="">All years</option>@foreach($this->academicYears as $year)<option value="{{ $year->id }}">{{ $year->name }}</option>@endforeach</flux:select>
            <flux:select wire:model.live="classLevelFilter" aria-label="Class level"><option value="">All classes</option>@foreach($this->classLevels as $level)<option value="{{ $level->id }}">{{ $level->name }}</option>@endforeach</flux:select>
            <flux:select wire:model.live="genderFilter" aria-label="Gender"><option value="">All genders</option>@foreach(Gender::cases() as $gender)<option value="{{ $gender->value }}">{{ $gender->label() }}</option>@endforeach</flux:select>
            <div class="flex gap-2"><flux:select class="flex-1" wire:model.live="statusFilter" aria-label="Student status"><option value="">All statuses</option>@foreach(StudentStatus::cases() as $status)<option value="{{ $status->value }}">{{ $status->label() }}</option>@endforeach</flux:select><flux:button wire:click="resetFilters" variant="ghost">Clear</flux:button></div>
        </div>
        <div class="overflow-x-auto"><table class="w-full min-w-5xl text-left text-sm">
            <thead class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-800"><tr><th class="px-4 py-3">Student</th><th class="px-4 py-3">Admission no.</th><th class="px-4 py-3">Class / Year</th><th class="px-4 py-3">Primary guardian</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Actions</th></tr></thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">@forelse($this->students as $student) @php($guardian = $student->primaryGuardians->first())
                <tr wire:key="student-{{ $student->id }}">
                    <td class="px-4 py-3"><div class="flex items-center gap-3">@if($student->photoUrl())<img src="{{ $student->photoUrl() }}" alt="" class="size-10 rounded-full object-cover">@else<div class="grid size-10 place-items-center rounded-full bg-zinc-100 font-semibold text-zinc-600 dark:bg-zinc-800">{{ str($student->first_name)->substr(0, 1) }}{{ str($student->last_name)->substr(0, 1) }}</div>@endif<div><p class="font-semibold">{{ $student->fullName() }}</p><p class="text-xs text-zinc-500">{{ $student->gender->label() }}</p></div></div></td>
                    <td class="px-4 py-3 font-mono text-xs">{{ $student->admission_number }}</td>
                    <td class="px-4 py-3">@if($student->currentEnrollment)<p class="font-medium">{{ $student->currentEnrollment->classLevel->name }}</p><p class="text-xs text-zinc-500">{{ $student->currentEnrollment->academicYear->name }}</p>@else<span class="text-zinc-500">Not currently enrolled</span>@endif</td>
                    <td class="px-4 py-3">@if($guardian)<p>{{ $guardian->fullName() }}</p><p class="text-xs text-zinc-500">{{ $guardian->phone }}</p>@else<span class="text-zinc-500">Not set</span>@endif</td>
                    <td class="px-4 py-3"><flux:badge :color="$student->status === StudentStatus::Active ? 'green' : 'zinc'">{{ $student->status->label() }}</flux:badge></td>
                    <td class="px-4 py-3"><div class="flex justify-end gap-2"><flux:button size="sm" variant="ghost" :href="route('students.show', $student)" wire:navigate>View</flux:button>@can(Permissions::STUDENTS_UPDATE)<flux:button size="sm" variant="ghost" icon="pencil-square" :href="route('students.edit', $student)" wire:navigate aria-label="Edit {{ $student->fullName() }}" />@endcan</div></td>
                </tr>
            @empty<tr><td colspan="6" class="px-4 py-12 text-center text-zinc-500">No students match the current filters.</td></tr>@endforelse</tbody>
        </table></div>
        @if($this->students->hasPages())<div class="border-t border-zinc-200 p-4 dark:border-zinc-800">{{ $this->students->links() }}</div>@endif
    </x-app.panel>
</div>
