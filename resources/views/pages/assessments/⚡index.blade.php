<?php

use App\Actions\Assessments\DeleteAssessment;
use App\AssessmentStatus;
use App\AssessmentType;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\ClassLevel;
use App\Models\Subject;
use App\Models\Term;
use App\Support\Academic\AcademicContext;
use App\Support\Academic\AssessmentAccess;
use App\Support\Settings\SystemSettings;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Assessments')] class extends Component {
    use WithPagination;

    #[Url] public string $academicYearId = '';
    #[Url] public string $termId = '';
    #[Url] public string $classLevelId = '';
    #[Url] public string $subjectId = '';
    #[Url] public string $typeFilter = '';
    #[Url] public string $statusFilter = '';
    #[Url] public string $search = '';
    public string $message = '';

    public function boot(): void { Gate::authorize('viewAny', Assessment::class); }
    public function mount(AcademicContext $context): void
    {
        $this->academicYearId = $this->academicYearId ?: (string) ($context->currentYearId() ?? '');
        $this->termId = $this->termId ?: (string) ($context->currentTermId() ?? '');
    }
    public function updated(string $property): void
    {
        if ($property === 'academicYearId') { $this->termId = ''; $this->subjectId = ''; }
        if ($property === 'classLevelId') { $this->subjectId = ''; }
        $this->resetPage();
    }
    public function deleteAssessment(int $id, DeleteAssessment $delete): void
    {
        $delete->handle(auth()->user(), $id);
        $this->message = 'Assessment deleted.';
        $this->resetPage();
    }
    #[Computed] public function years() { return AcademicYear::query()->orderByDesc('name')->get(['id', 'name']); }
    #[Computed] public function terms() { return Term::query()->where('academic_year_id', $this->academicYearId)->orderBy('term_order')->get(['id', 'name']); }
    #[Computed] public function classes()
    {
        return ClassLevel::query()->when(! AssessmentAccess::allSubjects(auth()->user()), fn ($q) => $q->whereIn('id', AssessmentAccess::assignments(auth()->user())->select('class_level_id')->when($this->academicYearId !== '', fn ($q) => $q->where('academic_year_id', $this->academicYearId))))->orderBy('level_order')->get(['id', 'name']);
    }
    #[Computed] public function subjects()
    {
        return Subject::query()->whereIn('id', AssessmentAccess::assignments(auth()->user())->select('subject_id')
            ->when($this->academicYearId !== '', fn ($q) => $q->where('academic_year_id', $this->academicYearId))
            ->when($this->classLevelId !== '', fn ($q) => $q->where('class_level_id', $this->classLevelId)))->orderBy('name')->get(['id', 'name']);
    }
    #[Computed] public function assessments()
    {
        return AssessmentAccess::assessments(auth()->user())->with(['subject:id,name', 'classLevel:id,name', 'term:id,name', 'academicYear:id,name'])
            ->withCount(['scores' => fn ($q) => $q->whereNotNull('score')])
            ->when($this->academicYearId !== '', fn ($q) => $q->where('academic_year_id', $this->academicYearId))
            ->when($this->termId !== '', fn ($q) => $q->where('term_id', $this->termId))
            ->when($this->classLevelId !== '', fn ($q) => $q->where('class_level_id', $this->classLevelId))
            ->when($this->subjectId !== '', fn ($q) => $q->where('subject_id', $this->subjectId))
            ->when($this->typeFilter !== '', fn ($q) => $q->where('type', $this->typeFilter))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when(trim($this->search) !== '', fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%'))
            ->latest('id')->paginate(app(SystemSettings::class)->recordsPerPage());
    }
};
?>

<div class="grid gap-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><flux:heading size="xl">Assessments</flux:heading><flux:text>Set up assessments, then open a class register to enter scores.</flux:text></div>
        @can('create', Assessment::class)<flux:button :href="route('assessments.create')" variant="primary" wire:navigate>Create Assessment</flux:button>@endcan
    </div>
    @if($message)<flux:callout variant="success">{{ $message }}</flux:callout>@endif
    <flux:error name="assessment" />
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <flux:select label="Academic year" wire:model.live="academicYearId"><option value="">All years</option>@foreach($this->years as $year)<option wire:key="year-{{ $year->id }}" value="{{ $year->id }}">{{ $year->name }}</option>@endforeach</flux:select>
        <flux:select label="Term" wire:model.live="termId"><option value="">All terms</option>@foreach($this->terms as $term)<option wire:key="term-{{ $term->id }}" value="{{ $term->id }}">{{ $term->name }}</option>@endforeach</flux:select>
        <flux:select label="Class" wire:model.live="classLevelId"><option value="">All classes</option>@foreach($this->classes as $class)<option wire:key="class-{{ $class->id }}" value="{{ $class->id }}">{{ $class->name }}</option>@endforeach</flux:select>
        <flux:select label="Subject" wire:model.live="subjectId"><option value="">All subjects</option>@foreach($this->subjects as $subject)<option wire:key="subject-{{ $subject->id }}" value="{{ $subject->id }}">{{ $subject->name }}</option>@endforeach</flux:select>
        <flux:select label="Type" wire:model.live="typeFilter"><option value="">All types</option>@foreach(AssessmentType::cases() as $type)<option wire:key="type-{{ $type->value }}" value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach</flux:select>
        <flux:select label="Status" wire:model.live="statusFilter"><option value="">All statuses</option>@foreach(AssessmentStatus::cases() as $status)<option wire:key="state-{{ $status->value }}" value="{{ $status->value }}">{{ $status->label() }}</option>@endforeach</flux:select>
        <flux:input label="Search" wire:model.live.debounce.400ms="search" placeholder="Assessment name" />
    </div>
    <div wire:loading role="status">Loading assessments…</div>
    <x-app.panel title="Assessment register">
        <div class="divide-y divide-zinc-200 dark:divide-zinc-800">
            @forelse($this->assessments as $assessment)
                <article wire:key="assessment-{{ $assessment->id }}" class="grid gap-3 p-4 lg:grid-cols-4 lg:items-center">
                    <div><p class="font-semibold">{{ $assessment->name }}</p><flux:text>{{ $assessment->subject->name }} · {{ $assessment->classLevel->name }}</flux:text></div>
                    <div><p>{{ $assessment->academicYear->name }} · {{ $assessment->term->name }}</p><flux:text>{{ $assessment->type->label() }} · Maximum {{ $assessment->maximum_score }}</flux:text></div>
                    <div><flux:badge>{{ $assessment->status->label() }}</flux:badge><flux:text>{{ $assessment->scores_count }} scores entered</flux:text></div>
                    <div class="flex flex-wrap gap-2">
                        <flux:button size="sm" :href="route('assessments.scores', $assessment)" wire:navigate>Scores</flux:button>
                        @can('update', $assessment)<flux:button size="sm" :href="route('assessments.edit', $assessment)" wire:navigate>Edit</flux:button>@endcan
                        @can('delete', $assessment)<flux:button size="sm" variant="danger" wire:click="deleteAssessment({{ $assessment->id }})" wire:confirm="Delete this assessment? Assessments with saved scores cannot be deleted.">Delete</flux:button>@endcan
                    </div>
                </article>
            @empty<div class="p-8 text-center text-zinc-500">No assessments match your filters.</div>@endforelse
        </div>
        <div class="p-4">{{ $this->assessments->links() }}</div>
    </x-app.panel>
</div>