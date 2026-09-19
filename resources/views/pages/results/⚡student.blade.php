<?php

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Term;
use App\Support\Academic\AcademicContext;
use App\Support\Academic\AssessmentAccess;
use App\Support\Academic\ResultCalculator;
use App\Support\Authorization\Permissions;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Student Results')] class extends Component {
    #[Locked] public int $studentId;
    #[Url] public string $academicYearId = '';
    #[Url] public string $termId = '';
    #[Url] public string $enrollmentId = '';

    public function boot(): void { Gate::authorize(Permissions::RESULTS_VIEW); }
    public function mount(Student $student, AcademicContext $context): void
    {
        $this->studentId = $student->id;
        $this->academicYearId = $this->academicYearId ?: (string) ($context->currentYearId() ?? '');
        $this->termId = $this->termId ?: (string) ($context->currentTermId() ?? '');
    }
    public function updatedAcademicYearId(): void { $this->termId = ''; $this->enrollmentId = ''; }
    #[Computed] public function enrollments()
    {
        return Enrollment::query()->where('student_id', $this->studentId)->where('academic_year_id', $this->academicYearId)
            ->when(! AssessmentAccess::allSubjects(auth()->user()), fn ($q) => $q->whereExists(AssessmentAccess::assignments(auth()->user())->selectRaw('1')->whereColumn('class_subjects.academic_year_id', 'enrollments.academic_year_id')->whereColumn('class_subjects.class_level_id', 'enrollments.class_level_id')))
            ->with(['student:id,first_name,middle_name,last_name,admission_number', 'classLevel:id,name'])->orderByDesc('id')->get();
    }
    #[Computed] public function selectedEnrollment(): ?Enrollment
    {
        return $this->enrollmentId === '' ? $this->enrollments->first() : $this->enrollments->firstWhere('id', (int) $this->enrollmentId);
    }
    #[Computed] public function years() { return AcademicYear::query()->orderByDesc('name')->get(['id', 'name']); }
    #[Computed] public function terms() { return Term::query()->where('academic_year_id', $this->academicYearId)->orderBy('term_order')->get(['id', 'name']); }
    #[Computed] public function result(): ?array
    {
        $enrollment = $this->selectedEnrollment;
        if ($enrollment === null || ! $this->terms->contains('id', (int) $this->termId)) { return null; }

        return app(ResultCalculator::class)->forClass(auth()->user(), (int) $this->academicYearId, (int) $this->termId, $enrollment->class_level_id, collect([$enrollment]))[$enrollment->id];
    }
};
?>

<div class="mx-auto grid w-full max-w-5xl gap-5">
    <div class="flex flex-wrap items-center justify-between gap-3"><flux:heading size="xl">Student Results</flux:heading><flux:button class="print:hidden" :href="route('results.index')" wire:navigate>Class Results</flux:button></div>
    <div class="grid gap-3 sm:grid-cols-3 print:hidden">
        <flux:select label="Academic year" wire:model.live="academicYearId"><option value="">Select year</option>@foreach($this->years as $year)<option wire:key="year-{{ $year->id }}" value="{{ $year->id }}">{{ $year->name }}</option>@endforeach</flux:select>
        <flux:select label="Term" wire:model.live="termId"><option value="">Select term</option>@foreach($this->terms as $term)<option wire:key="term-{{ $term->id }}" value="{{ $term->id }}">{{ $term->name }}</option>@endforeach</flux:select>
        <flux:select label="Historical enrollment" wire:model.live="enrollmentId"><option value="">Latest enrollment in selected year</option>@foreach($this->enrollments as $enrollment)<option wire:key="enrollment-{{ $enrollment->id }}" value="{{ $enrollment->id }}">{{ $enrollment->classLevel->name }} · {{ $enrollment->enrollment_date->toDateString() }}</option>@endforeach</flux:select>
    </div>
    <div wire:loading role="status">Loading student results…</div>
    @if($this->result !== null)
        <div><flux:heading size="lg">{{ $this->selectedEnrollment->student->fullName() }}</flux:heading><flux:text>{{ $this->selectedEnrollment->student->admission_number }} · {{ $this->selectedEnrollment->classLevel->name }} · {{ $this->years->firstWhere('id', (int) $academicYearId)?->name }} · {{ $this->terms->firstWhere('id', (int) $termId)?->name }}</flux:text></div>
        <flux:text>Only authorized subjects are shown. Missing scores are not zero; percentages are withheld until a result is complete.</flux:text>
        @forelse($this->result['subjects'] as $id => $subject)
            <article wire:key="subject-{{ $id }}" class="break-inside-avoid rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading>{{ $subject['name'] }}</flux:heading>
                <div class="mt-3 divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse($subject['assessments'] as $index => $assessment)
                        <div wire:key="breakdown-{{ $id }}-{{ $index }}" class="flex justify-between gap-3 py-2"><span>{{ $assessment['name'] }}</span><span>{{ $assessment['score'] ?? 'Missing' }} / {{ $assessment['maximum'] }}</span></div>
                    @empty<flux:text>No assessment configured.</flux:text>@endforelse
                </div>
                <p class="mt-3 font-semibold">Total: {{ $subject['earned'] }} / {{ $subject['maximum'] }} · {{ $subject['complete'] ? $subject['percentage'].'% — Complete' : 'Incomplete' }}</p>
            </article>
        @empty<flux:callout>No subjects or assessments configured.</flux:callout>@endforelse
        <p class="font-semibold">Total for displayed subjects: {{ $this->result['earned'] }} / {{ $this->result['maximum'] }} · {{ $this->result['complete'] ? $this->result['percentage'].'%' : 'Incomplete' }}</p>
    @else<flux:callout>No accessible result is available for this selection. Select a year and term with an authorized enrollment.</flux:callout>@endif
</div>