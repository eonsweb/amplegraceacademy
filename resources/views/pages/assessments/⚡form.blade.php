<?php

use App\Actions\Assessments\SaveAssessment;
use App\AssessmentStatus;
use App\AssessmentType;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\ClassLevel;
use App\Models\Subject;
use App\Models\Term;
use App\Support\Academic\AcademicContext;
use App\Support\Academic\AssessmentAccess;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Assessment Setup')] class extends Component {
    #[Locked] public ?int $assessmentId = null;
    public array $form = ['academic_year_id' => '', 'term_id' => '', 'class_level_id' => '', 'subject_id' => '', 'name' => '', 'type' => 'test', 'maximum_score' => '20', 'assessment_date' => null, 'status' => 'open'];
    public string $message = '';

    public function boot(): void { Gate::authorize('viewAny', Assessment::class); }
    public function mount(AcademicContext $context, ?Assessment $assessment = null): void
    {
        if ($assessment?->exists) {
            Gate::authorize('update', $assessment);
            $this->assessmentId = $assessment->id;
            $this->form = $assessment->only(array_keys($this->form));
            $this->form['type'] = $assessment->type->value;
            $this->form['status'] = $assessment->status->value;
            $this->form['assessment_date'] = $assessment->assessment_date?->toDateString();
        } else {
            Gate::authorize('create', Assessment::class);
            $this->form['academic_year_id'] = $context->currentYearId() ?? '';
            $this->form['term_id'] = $context->currentTermId() ?? '';
        }
    }
    public function updatedForm(mixed $value, string $key): void
    {
        if ($key === 'academic_year_id') { $this->form['term_id'] = ''; $this->form['subject_id'] = ''; }
        if ($key === 'class_level_id') { $this->form['subject_id'] = ''; }
    }
    public function save(SaveAssessment $save): void
    {
        $assessment = $save->handle(auth()->user(), $this->form, $this->assessmentId);
        $this->redirectRoute('assessments.scores', ['assessment' => $assessment->id], navigate: true);
    }
    #[Computed] public function years() { return AcademicYear::query()->orderByDesc('name')->get(['id', 'name']); }
    #[Computed] public function terms() { return Term::query()->where('academic_year_id', $this->form['academic_year_id'])->orderBy('term_order')->get(['id', 'name']); }
    #[Computed] public function classes()
    {
        return ClassLevel::query()->whereIn('id', AssessmentAccess::assignments(auth()->user(), true)->select('class_level_id')->where('academic_year_id', $this->form['academic_year_id']))->orderBy('level_order')->get(['id', 'name']);
    }
    #[Computed] public function subjects()
    {
        return Subject::query()->whereIn('id', AssessmentAccess::assignments(auth()->user(), true)->select('subject_id')
            ->where('academic_year_id', $this->form['academic_year_id'])->where('class_level_id', $this->form['class_level_id']))->orderBy('name')->get(['id', 'name']);
    }
};
?>

<div class="mx-auto grid w-full max-w-4xl gap-5">
    <div class="flex flex-wrap items-center justify-between gap-3"><flux:heading size="xl">{{ $assessmentId ? 'Edit Assessment' : 'Create Assessment' }}</flux:heading><flux:button :href="route('assessments.index')" wire:navigate>Assessments</flux:button></div>
    <flux:text>Subjects follow class assignments. After scores are saved, the academic context, date and maximum score are fixed to preserve results.</flux:text>
    @if($errors->any())<div role="alert" class="rounded-lg border border-red-300 p-3 text-red-700 dark:text-red-300">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
        <flux:select label="Academic year" wire:model.live="form.academic_year_id"><option value="">Select year</option>@foreach($this->years as $year)<option wire:key="year-{{ $year->id }}" value="{{ $year->id }}">{{ $year->name }}</option>@endforeach</flux:select>
        <flux:select label="Term" wire:model="form.term_id"><option value="">Select term</option>@foreach($this->terms as $term)<option wire:key="term-{{ $term->id }}" value="{{ $term->id }}">{{ $term->name }}</option>@endforeach</flux:select>
        <flux:select label="Class" wire:model.live="form.class_level_id"><option value="">Select class</option>@foreach($this->classes as $class)<option wire:key="class-{{ $class->id }}" value="{{ $class->id }}">{{ $class->name }}</option>@endforeach</flux:select>
        <flux:select label="Subject" wire:model="form.subject_id"><option value="">Select subject</option>@foreach($this->subjects as $subject)<option wire:key="subject-{{ $subject->id }}" value="{{ $subject->id }}">{{ $subject->name }}</option>@endforeach</flux:select>
        <flux:input label="Assessment name" wire:model="form.name" maxlength="150" required />
        <flux:select label="Type" wire:model="form.type">@foreach(AssessmentType::cases() as $type)<option wire:key="type-{{ $type->value }}" value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach</flux:select>
        <flux:input label="Maximum score" type="number" min="0.01" max="999999.99" step="0.01" wire:model="form.maximum_score" required />
        <flux:input label="Assessment date (optional)" type="date" wire:model="form.assessment_date" />
        <flux:select label="Status" wire:model="form.status">@foreach(AssessmentStatus::cases() as $status)<option wire:key="state-{{ $status->value }}" value="{{ $status->value }}">{{ $status->label() }}</option>@endforeach</flux:select>
        <div class="flex items-end"><flux:button type="submit" variant="primary" wire:loading.attr="disabled">Save Assessment</flux:button></div>
    </form>
</div>