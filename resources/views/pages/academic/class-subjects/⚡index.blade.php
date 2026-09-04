<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\ClassSubject;
use App\Models\Subject;
use App\Models\User;
use App\Support\Academic\AcademicContext;
use App\Support\Authorization\Permissions;
use App\Support\Authorization\Roles;
use App\Support\Settings\SystemSettings;
use Flux\Flux;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Class Subjects')] class extends Component {
    use WithPagination;

    #[Url] public ?int $academicYearId = null;
    #[Url] public ?int $classLevelId = null;
    #[Url] public string $search = '';
    public int $recordsPerPage = 25;

    public function mount(SystemSettings $settings, AcademicContext $context): void
    {
        abort_unless(Gate::any([Permissions::CLASSES_VIEW, Permissions::SUBJECTS_VIEW]), 403);
        $this->recordsPerPage = $settings->recordsPerPage();
        $this->academicYearId ??= $context->currentYearId() ?? AcademicYear::query()->orderByDesc('name')->value('id');
        $this->classLevelId ??= ClassLevel::query()->where('is_active', true)->orderBy('level_order')->value('id');
    }

    public function updatedAcademicYearId(): void { $this->resetPage(); }
    public function updatedClassLevelId(): void { $this->resetPage(); }
    public function updatedSearch(): void { $this->resetPage(); }

    /** @return Collection<int, AcademicYear> */
    #[Computed] public function years(): Collection { return AcademicYear::query()->select(['id', 'name', 'is_current'])->orderByDesc('name')->limit(50)->get(); }
    /** @return Collection<int, ClassLevel> */
    #[Computed] public function levels(): Collection { return ClassLevel::query()->select(['id', 'name', 'level_order', 'is_active'])->orderBy('level_order')->orderBy('id')->limit(100)->get(); }
    /** @return Collection<int, User> */
    #[Computed] public function teachers(): Collection
    {
        return User::query()->select(['id', 'name'])->where('is_active', true)->role(Roles::TEACHER)->orderBy('name')->limit(100)->get();
    }

    /** @return LengthAwarePaginator<int, Subject> */
    #[Computed]
    public function subjects(): LengthAwarePaginator
    {
        $yearId = $this->academicYearId ?? 0;
        $classLevelId = $this->classLevelId ?? 0;
        $search = trim($this->search);

        return Subject::query()
            ->leftJoin('class_subjects as assignment', function (JoinClause $join) use ($yearId, $classLevelId): void {
                $join->on('assignment.subject_id', '=', 'subjects.id')->where('assignment.academic_year_id', $yearId)->where('assignment.class_level_id', $classLevelId);
            })
            ->select(['subjects.id', 'subjects.name', 'subjects.code', 'assignment.id as assignment_id', 'assignment.staff_id as assigned_staff_id'])
            ->where('subjects.is_active', true)
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested->where('subjects.name', 'like', '%'.$search.'%')->orWhere('subjects.code', 'like', '%'.$search.'%')))
            ->orderBy('subjects.name')->orderBy('subjects.id')->paginate($this->recordsPerPage);
    }

    /** @return array<int, array{subject_id: int, assigned: bool, staff_id: int|null}> */
    #[Computed]
    public function assignmentState(): array
    {
        return collect($this->subjects->items())->mapWithKeys(fn (Subject $subject): array => [
            $subject->id => ['subject_id' => $subject->id, 'assigned' => $subject->getAttribute('assignment_id') !== null, 'staff_id' => $subject->getAttribute('assigned_staff_id')],
        ])->all();
    }

    /** @param array<int, array{subject_id: int, assigned: bool, staff_id: int|string|null}> $assignments */
    public function saveAssignments(array $assignments): void
    {
        abort_unless(Gate::any([Permissions::CLASSES_UPDATE, Permissions::SUBJECTS_UPDATE]), 403);
        $validated = Validator::make(['assignments' => $assignments], [
            'assignments' => ['required', 'array', 'max:'.$this->recordsPerPage],
            'assignments.*.subject_id' => ['required', 'integer', 'distinct'],
            'assignments.*.assigned' => ['required', 'boolean'],
            'assignments.*.staff_id' => ['nullable', 'integer'],
        ])->validate()['assignments'];

        $yearId = AcademicYear::query()->whereKey($this->academicYearId)->value('id');
        $classLevelId = ClassLevel::query()->whereKey($this->classLevelId)->value('id');
        if ($yearId === null || $classLevelId === null) { throw ValidationException::withMessages(['assignment' => 'Select a valid academic year and class level.']); }

        $subjectIds = collect($validated)->pluck('subject_id')->map(fn ($id): int => (int) $id)->values();
        $allowedSubjectIds = Subject::query()->where('is_active', true)->whereIn('id', $subjectIds)->pluck('id');
        if ($allowedSubjectIds->count() !== $subjectIds->unique()->count()) { throw ValidationException::withMessages(['assignment' => 'One or more subjects are no longer available. Refresh and try again.']); }

        $staffIds = collect($validated)->where('assigned', true)->pluck('staff_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        $allowedStaffIds = User::query()->where('is_active', true)->role(Roles::TEACHER)->whereIn('id', $staffIds)->pluck('id');
        if ($allowedStaffIds->count() !== $staffIds->count()) { throw ValidationException::withMessages(['assignment' => 'One or more selected teachers are unavailable.']); }

        $assigned = collect($validated)->where('assigned', true);
        $now = now();
        $rows = $assigned->map(fn (array $assignment): array => ['academic_year_id' => $yearId, 'class_level_id' => $classLevelId, 'subject_id' => (int) $assignment['subject_id'], 'staff_id' => $assignment['staff_id'] ?: null, 'is_elective' => false, 'created_at' => $now, 'updated_at' => $now])->all();
        DB::transaction(function () use ($yearId, $classLevelId, $subjectIds, $assigned, $rows): void {
            ClassSubject::query()->where('academic_year_id', $yearId)->where('class_level_id', $classLevelId)->whereIn('subject_id', $subjectIds)->whereNotIn('subject_id', $assigned->pluck('subject_id'))->delete();
            if ($rows !== []) { ClassSubject::query()->upsert($rows, ['academic_year_id', 'class_level_id', 'subject_id'], ['staff_id', 'updated_at']); }
        });
        unset($this->subjects, $this->assignmentState);
        Flux::toast(variant: 'success', text: 'Class subject assignments saved.');
    }
};
?>

<x-academic.layout heading="Class Subjects" subheading="Assign subjects and teachers to classes for an academic year.">
    <div class="grid gap-5">
        <div class="grid gap-4 sm:grid-cols-2"><flux:select wire:model.live="academicYearId" label="Academic Year"><flux:select.option value="">Select a year</flux:select.option>@foreach($this->years as $year)<flux:select.option value="{{ $year->id }}">{{ $year->name }}{{ $year->is_current ? ' (Current)' : '' }}</flux:select.option>@endforeach</flux:select><flux:select wire:model.live="classLevelId" label="Class Level"><flux:select.option value="">Select a level</flux:select.option>@foreach($this->levels as $level)<flux:select.option value="{{ $level->id }}">{{ $level->name }}{{ $level->is_active ? '' : ' (Inactive)' }}</flux:select.option>@endforeach</flux:select></div>
        @error('assignment')<flux:callout variant="danger" icon="exclamation-circle" heading="{{ $message }}" />@enderror
        <x-app.panel title="Subject assignments">
            @if($academicYearId && $classLevelId)
                <div class="border-b border-zinc-200 p-4 dark:border-zinc-800"><flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Search active subjects..." /></div>
                <div wire:key="assignment-editor-{{ $academicYearId }}-{{ $classLevelId }}-{{ $this->subjects->currentPage() }}" x-data="{ assignments: @js($this->assignmentState) }">
                    <div class="overflow-x-auto"><table class="w-full min-w-2xl text-left text-sm"><thead class="border-b text-xs uppercase text-zinc-500"><tr><th class="px-4 py-3">Assigned</th><th class="px-4 py-3">Subject</th><th class="px-4 py-3">Teacher (optional)</th></tr></thead><tbody class="divide-y dark:divide-zinc-800">@forelse($this->subjects as $subject)<tr wire:key="assignment-subject-{{ $subject->id }}"><td class="px-4 py-3"><input type="checkbox" x-model="assignments[{{ $subject->id }}].assigned" class="size-4 rounded border-zinc-300 text-brand-700 focus:ring-brand-600"></td><td class="px-4 py-3"><span class="font-semibold">{{ $subject->name }}</span>@if($subject->code)<span class="ml-2 text-xs text-zinc-500">{{ $subject->code }}</span>@endif</td><td class="px-4 py-3"><select x-model="assignments[{{ $subject->id }}].staff_id" x-bind:disabled="!assignments[{{ $subject->id }}].assigned" class="w-full rounded-lg border-zinc-300 bg-white text-sm disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900"><option value="">No teacher</option>@foreach($this->teachers as $teacher)<option value="{{ $teacher->id }}">{{ $teacher->name }}</option>@endforeach</select></td></tr>@empty<tr><td colspan="3"><x-academic.empty-state class="border-0" icon="check-circle" heading="No active subjects found" description="Create or activate subjects before assigning them." /></td></tr>@endforelse</tbody></table></div>
                    <div class="flex flex-col gap-3 border-t border-zinc-200 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800"><div>@if($this->subjects->hasPages()){{ $this->subjects->links() }}@endif</div>@if(auth()->user()->canAny([Permissions::CLASSES_UPDATE, Permissions::SUBJECTS_UPDATE]))<flux:button variant="primary" x-on:click="$wire.saveAssignments(Object.values(assignments))" wire:loading.attr="disabled" wire:target="saveAssignments">Save assignments</flux:button>@endif</div>
                </div>
            @else
                <x-academic.empty-state icon="check-circle" heading="Select a class level" description="Choose an academic year and class level to edit one paginated batch." />
            @endif
        </x-app.panel>
    </div>
</x-academic.layout>
