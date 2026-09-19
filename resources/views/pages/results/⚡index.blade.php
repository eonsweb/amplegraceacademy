<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\Enrollment;
use App\Models\Subject;
use App\Models\Term;
use App\Support\Academic\AcademicContext;
use App\Support\Academic\AssessmentAccess;
use App\Support\Academic\ResultCalculator;
use App\Support\Authorization\Permissions;
use App\Support\Settings\SystemSettings;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Class Results')] class extends Component {
    use WithPagination;

    #[Url] public string $academicYearId = '';
    #[Url] public string $termId = '';
    #[Url] public string $classLevelId = '';
    #[Url] public string $subjectId = '';
    #[Url] public string $search = '';

    public function boot(): void { Gate::authorize(Permissions::RESULTS_VIEW); }
    public function mount(AcademicContext $context): void
    {
        $this->academicYearId = $this->academicYearId ?: (string) ($context->currentYearId() ?? '');
        $this->termId = $this->termId ?: (string) ($context->currentTermId() ?? '');
    }
    public function updated(string $property): void
    {
        if ($property === 'academicYearId') { $this->termId = ''; $this->classLevelId = ''; }
        if (in_array($property, ['academicYearId', 'classLevelId'], true)) { $this->subjectId = ''; }
        $this->resetPage();
    }
    #[Computed] public function years() { return AcademicYear::query()->orderByDesc('name')->get(['id', 'name']); }
    #[Computed] public function terms() { return Term::query()->where('academic_year_id', $this->academicYearId)->orderBy('term_order')->get(['id', 'name']); }
    #[Computed] public function classes()
    {
        return ClassLevel::query()->when(! AssessmentAccess::allSubjects(auth()->user()), fn ($q) => $q->whereIn('id', AssessmentAccess::assignments(auth()->user())->select('class_level_id')->where('academic_year_id', $this->academicYearId)))->orderBy('level_order')->get(['id', 'name']);
    }
    #[Computed] public function subjects()
    {
        $ids = AssessmentAccess::assignments(auth()->user())->where('academic_year_id', $this->academicYearId)->where('class_level_id', $this->classLevelId)->pluck('subject_id')
            ->merge(AssessmentAccess::assessments(auth()->user())->where('academic_year_id', $this->academicYearId)->where('class_level_id', $this->classLevelId)->pluck('subject_id'));

        return Subject::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }
    #[Computed] public function enrollments()
    {
        $query = Enrollment::query()->where('academic_year_id', $this->academicYearId)->where('class_level_id', $this->classLevelId)
            ->with('student:id,first_name,middle_name,last_name,admission_number');
        if (! $this->classes->contains('id', (int) $this->classLevelId)) { $query->whereRaw('1 = 0'); }
        if (trim($this->search) !== '') {
            $query->whereHas('student', function ($q): void {
                foreach (preg_split('/\s+/', trim($this->search)) as $word) {
                    $q->where(fn ($q) => $q->where('first_name', 'like', '%'.$word.'%')->orWhere('last_name', 'like', '%'.$word.'%')->orWhere('middle_name', 'like', '%'.$word.'%')->orWhere('admission_number', 'like', '%'.$word.'%'));
                }
            });
        }

        return $query->orderBy('id')->paginate(app(SystemSettings::class)->recordsPerPage());
    }
    #[Computed] public function results(): array
    {
        if (! $this->terms->contains('id', (int) $this->termId) || $this->enrollments->isEmpty()) { return []; }

        return app(ResultCalculator::class)->forClass(auth()->user(), (int) $this->academicYearId, (int) $this->termId, (int) $this->classLevelId, collect($this->enrollments->items()), $this->subjectId !== '' ? (int) $this->subjectId : null);
    }
};
?>

<div class="grid gap-5">
    <div><flux:heading size="xl">Class Results</flux:heading><flux:text>Totals use the sum of assessment maximums. Percentages appear only when all required scores are entered. Results reflect subjects you are authorized to view.</flux:text></div>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <flux:select label="Academic year" wire:model.live="academicYearId"><option value="">Select year</option>@foreach($this->years as $year)<option wire:key="year-{{ $year->id }}" value="{{ $year->id }}">{{ $year->name }}</option>@endforeach</flux:select>
        <flux:select label="Term" wire:model.live="termId"><option value="">Select term</option>@foreach($this->terms as $term)<option wire:key="term-{{ $term->id }}" value="{{ $term->id }}">{{ $term->name }}</option>@endforeach</flux:select>
        <flux:select label="Class" wire:model.live="classLevelId"><option value="">Select class</option>@foreach($this->classes as $class)<option wire:key="class-{{ $class->id }}" value="{{ $class->id }}">{{ $class->name }}</option>@endforeach</flux:select>
        <flux:select label="Subject" wire:model.live="subjectId"><option value="">All authorized subjects</option>@foreach($this->subjects as $subject)<option wire:key="subject-{{ $subject->id }}" value="{{ $subject->id }}">{{ $subject->name }}</option>@endforeach</flux:select>
        <flux:input label="Student" wire:model.live.debounce.400ms="search" placeholder="Name or admission number" />
    </div>
    <div wire:loading role="status">Loading results…</div>
    @if(! $this->results)<flux:callout>Select a year, term and class with eligible enrollments to view results.</flux:callout>@else
    <div class="grid gap-4">
        @foreach($this->enrollments as $enrollment)
            @php($result = $this->results[$enrollment->id])
            <article wire:key="result-{{ $enrollment->id }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div><flux:heading>{{ $enrollment->student->fullName() }}</flux:heading><flux:text>{{ $enrollment->student->admission_number }}</flux:text></div>
                    <flux:button size="sm" :href="route('results.student', ['student' => $enrollment->student_id, 'academicYearId' => $academicYearId, 'termId' => $termId, 'enrollmentId' => $enrollment->id])" wire:navigate>Student Results</flux:button>
                </div>
                <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    @forelse($result['subjects'] as $id => $subject)
                        <div wire:key="subject-{{ $enrollment->id }}-{{ $id }}" class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800">
                            <p class="font-medium">{{ $subject['name'] }}</p>
                            <p>{{ $subject['earned'] }} / {{ $subject['maximum'] }} @if($subject['percentage'] !== null) · {{ $subject['percentage'] }}%@endif</p>
                            <flux:text>{{ ! $subject['assessments'] ? 'No assessment configured' : ($subject['complete'] ? 'Complete' : $subject['missing'].' missing scores') }}</flux:text>
                        </div>
                    @empty<flux:text>No subjects or assessments configured.</flux:text>@endforelse
                </div>
                <p class="mt-3 font-medium">Total for displayed subjects: {{ $result['earned'] }} / {{ $result['maximum'] }} · {{ $result['complete'] ? $result['percentage'].'% — Complete' : 'Incomplete' }}</p>
            </article>
        @endforeach
    </div>
    {{ $this->enrollments->links() }}
    @endif
</div>
