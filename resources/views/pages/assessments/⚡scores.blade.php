<?php

use App\Actions\Assessments\SaveAssessmentScores;
use App\AssessmentStatus;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Assessment Scores')] class extends Component {
    #[Locked] public int $assessmentId;
    #[Locked] public int $pageNumber = 1;
    #[Locked] public bool $hasNextPage = false;
    #[Locked] public array $roster = [];
    public array $rows = [];
    public string $message = '';

    public function boot(): void { Gate::authorize('viewAny', Assessment::class); }
    public function mount(Assessment $assessment): void
    {
        $this->assessmentId = $assessment->id;
        $this->loadRoster();
    }
    #[Computed] public function assessment(): Assessment
    {
        $assessment = Assessment::query()->with(['subject:id,name', 'classLevel:id,name', 'academicYear:id,name', 'term:id,name'])->findOrFail($this->assessmentId);
        Gate::authorize('view', $assessment);

        return $assessment;
    }
    public function loadRoster(): void
    {
        $assessment = $this->assessment;
        $page = $assessment->roster()->with('student:id,first_name,middle_name,last_name,admission_number')
            ->orderBy('id')->simplePaginate(100, ['id', 'student_id'], 'page', $this->pageNumber);
        $this->hasNextPage = $page->hasMorePages();
        $scores = AssessmentScore::query()->where('assessment_id', $assessment->id)->whereIn('enrollment_id', collect($page->items())->pluck('id'))->get()->keyBy('enrollment_id');
        $this->rows = [];
        $this->roster = [];
        foreach ($page as $enrollment) {
            $this->roster[$enrollment->id] = ['name' => $enrollment->student->fullName(), 'admission_number' => $enrollment->student->admission_number];
            $this->rows[$enrollment->id] = ['enrollment_id' => $enrollment->id, 'score' => $scores->get($enrollment->id)?->score];
        }
    }
    public function goToPage(int $page): void
    {
        abort_unless($page >= 1 && ($page <= $this->pageNumber || ($this->hasNextPage && $page === $this->pageNumber + 1)), 422);
        $this->pageNumber = $page;
        $this->resetValidation();
        $this->message = '';
        $this->loadRoster();
    }
    public function save(SaveAssessmentScores $save): void
    {
        $save->handle(auth()->user(), $this->assessmentId, $this->rows);
        $this->loadRoster();
        $this->message = 'Scores saved successfully.';
    }
};
?>

<div class="grid gap-5" x-data>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><flux:heading size="xl">{{ $this->assessment->name }}</flux:heading><flux:text>{{ $this->assessment->academicYear->name }} · {{ $this->assessment->term->name }} · {{ $this->assessment->classLevel->name }} · {{ $this->assessment->subject->name }}</flux:text></div>
        <flux:button :href="route('assessments.index')" wire:navigate>Assessments</flux:button>
    </div>
    <flux:text>Maximum score: {{ $this->assessment->maximum_score }}. Leave a score blank when it is missing; enter 0 for a zero score. Use Tab to move between students. Save each page before continuing.</flux:text>
    @if($message)<flux:callout variant="success" role="status">{{ $message }}</flux:callout>@endif
    @if($errors->any())<div role="alert" class="rounded-lg border border-red-300 p-3 text-red-700 dark:text-red-300">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @php($editable = Gate::allows('recordScores', $this->assessment) && $this->assessment->status === AssessmentStatus::Open)
    @if(! $editable)<flux:callout>This register is read only. Score entry requires permission and an open assessment.</flux:callout>@endif
    <div wire:loading role="status">Loading scores…</div>
    <form wire:submit="save" class="grid gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:badge><span x-text="Object.values($wire.rows).filter(row => row.score !== null && row.score !== '').length"></span> / {{ count($roster) }} entered on this page</flux:badge>
            <flux:button type="submit" variant="primary" :disabled="! $editable || ! $roster" wire:loading.attr="disabled">Save Scores</flux:button>
        </div>
        <x-app.panel title="Student scores">
            <div class="divide-y divide-zinc-200 dark:divide-zinc-800">
                @forelse($roster as $id => $student)
                    <div wire:key="score-{{ $assessmentId }}-{{ $id }}" class="grid gap-3 p-3 sm:grid-cols-[1fr_10rem_6rem] sm:items-center">
                        <div><p class="font-semibold">{{ $student['name'] }}</p><p class="font-mono text-xs text-zinc-500">{{ $student['admission_number'] }}</p></div>
                        <div><flux:input type="number" step="0.01" min="0" :max="$this->assessment->maximum_score" x-model="$wire.rows[{{ $id }}].score" :disabled="! $editable" aria-label="Score for {{ $student['name'] }}" /><flux:error name="rows.{{ $id }}.score" /></div>
                        <span class="text-sm text-zinc-500" x-text="$wire.rows[{{ $id }}].score === null || $wire.rows[{{ $id }}].score === '' ? 'Missing' : (Number($wire.rows[{{ $id }}].score) >= 0 && Number($wire.rows[{{ $id }}].score) <= {{ $this->assessment->maximum_score }} ? 'Entered' : 'Invalid')"></span>
                    </div>
                @empty<div class="p-8 text-center text-zinc-500">No eligible students are enrolled for this assessment.</div>@endforelse
            </div>
        </x-app.panel>
    </form>
    <div class="flex items-center justify-between gap-3">
        <flux:button :disabled="$pageNumber <= 1" x-on:click="if (!$wire.$dirty('rows') || confirm('Discard unsaved scores on this page?')) $wire.goToPage({{ $pageNumber - 1 }})">Previous</flux:button>
        <span>Page {{ $pageNumber }}</span>
        <flux:button :disabled="! $hasNextPage" x-on:click="if (!$wire.$dirty('rows') || confirm('Discard unsaved scores on this page?')) $wire.goToPage({{ $pageNumber + 1 }})">Next</flux:button>
    </div>
</div>