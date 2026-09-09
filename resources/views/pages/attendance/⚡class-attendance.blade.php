<?php

use App\Actions\Attendance\SaveClassAttendance;
use App\AttendanceStatus;
use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\ClassLevel;
use App\Models\ClassSubject;
use App\Models\Term;
use App\Policies\AttendancePolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Class Attendance')] class extends Component {
    public string $academicYearId = '';
    public string $termId = '';
    public string $classLevelId = '';
    public string $attendanceDate = '';
    /** @var array<int, array{enrollment_id: int, status: string, remark: string|null}> */
    public array $rows = [];
    /** @var array<int, array{name: string, admission_number: string, editable: bool}> */
    #[Locked] public array $roster = [];
    /** @var array<string, string> */
    #[Locked] public array $loadedSelection = [];
    public string $message = '';

    public function boot(): void
    {
        Gate::authorize('viewAny', Attendance::class);
    }

    public function mount(): void
    {
        $this->attendanceDate = today()->toDateString();
        $this->academicYearId = (string) (AcademicYear::query()->where('is_current', true)->value('id') ?? '');
        $this->termId = (string) (Term::query()->where('academic_year_id', $this->academicYearId)->where('is_current', true)->value('id') ?? '');
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['academicYearId', 'termId', 'classLevelId', 'attendanceDate'], true)) {
            return;
        }
        if ($property === 'academicYearId') {
            $this->termId = (string) (Term::query()->where('academic_year_id', $this->academicYearId)->where('is_current', true)->value('id') ?? '');
            $this->classLevelId = '';
        }
        $this->reset('rows', 'roster', 'loadedSelection', 'message');
        $this->resetValidation();
        if ($this->academicYearId !== '' && $this->termId !== '' && $this->classLevelId !== '' && $this->attendanceDate !== '') {
            $this->loadRoster();
        }
    }

    public function loadRoster(): void
    {
        $this->reset('rows', 'roster', 'loadedSelection', 'message');
        $selection = $this->validate(SaveClassAttendance::selectionRules($this->academicYearId));
        Gate::authorize('viewClass', [Attendance::class, (int) $this->academicYearId, (int) $this->classLevelId]);
        $enrollments = Attendance::roster((int) $this->academicYearId, (int) $this->classLevelId, $this->attendanceDate)
            ->select(['id', 'student_id'])->with('student:id,admission_number,first_name,middle_name,last_name')->get()
            ->sortBy(fn ($enrollment): string => $enrollment->student->last_name.' '.$enrollment->student->first_name.' '.$enrollment->id);
        $existing = Attendance::query()->whereIn('enrollment_id', $enrollments->modelKeys())->where('attendance_date', $this->attendanceDate)->get()->keyBy('enrollment_id');
        if ($existing->contains(fn (Attendance $attendance): bool => $attendance->term_id !== (int) $this->termId)) {
            $this->addError('termId', 'Attendance for this date is already recorded under another term. Select that term.');
            return;
        }
        $canRecord = Gate::allows('create', Attendance::class);
        $canEdit = Gate::allows('update', Attendance::class);
        foreach ($enrollments as $enrollment) {
            $attendance = $existing->get($enrollment->id);
            $this->rows[$enrollment->id] = ['enrollment_id' => $enrollment->id, 'status' => $attendance?->status->value ?? AttendanceStatus::Present->value, 'remark' => $attendance?->remark];
            $this->roster[$enrollment->id] = ['name' => $enrollment->student->fullName(), 'admission_number' => $enrollment->student->admission_number, 'editable' => $attendance === null ? $canRecord : $canEdit];
        }
        $this->loadedSelection = $selection;
    }

    public function save(SaveClassAttendance $saveAttendance): void
    {
        $selection = $this->validate(SaveClassAttendance::selectionRules($this->academicYearId));
        if ($selection !== $this->loadedSelection) {
            $this->addError('rows', 'Reload the class before saving attendance.');
            return;
        }
        $saveAttendance->handle(auth()->user(), [...$selection, 'rows' => $this->rows]);
        $this->loadRoster();
        $this->message = 'Attendance saved successfully.';
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
        return Term::query()->where('academic_year_id', $this->academicYearId)->orderBy('term_order')->get(['id', 'name']);
    }

    /** @return Collection<int, ClassLevel> */
    #[Computed]
    public function classes(): Collection
    {
        return ClassLevel::query()->when(AttendancePolicy::requiresAssignment(auth()->user()), fn ($query) => $query->whereIn('id', ClassSubject::query()->select('class_level_id')->where('academic_year_id', $this->academicYearId)->where('staff_id', auth()->id())))
            ->orderBy('level_order')->get(['id', 'name']);
    }
};
?>

<div class="grid gap-5" x-data>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><flux:heading size="xl">Class Attendance</flux:heading><flux:text>Select a class, change exceptions, then save attendance.</flux:text></div>
        <flux:button :href="route('attendance.history')" wire:navigate>Attendance History</flux:button>
    </div>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <flux:select label="Academic Year" wire:model.live="academicYearId"><option value="">Select year</option>@foreach($this->years as $year)<option wire:key="year-{{ $year->id }}" value="{{ $year->id }}">{{ $year->name }}</option>@endforeach</flux:select>
        <flux:select label="Term" wire:model.live="termId"><option value="">Select term</option>@foreach($this->terms as $term)<option wire:key="term-{{ $term->id }}" value="{{ $term->id }}">{{ $term->name }}</option>@endforeach</flux:select>
        <flux:select label="Class" wire:model.live="classLevelId"><option value="">Select class</option>@foreach($this->classes as $class)<option wire:key="class-{{ $class->id }}" value="{{ $class->id }}">{{ $class->name }}</option>@endforeach</flux:select>
        <flux:input label="Date" type="date" wire:model.live="attendanceDate" :max="today()->toDateString()" />
    </div>
    @if($errors->any())<div role="alert" class="rounded-lg border border-red-300 p-3 text-red-700 dark:text-red-300">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @if($message)<flux:callout variant="success" role="status">{{ $message }}</flux:callout>@endif
    <div wire:loading class="text-sm text-zinc-500" role="status">Loading attendance…</div>
    @if($roster)
        <div class="flex flex-wrap gap-3" aria-live="polite">
            <flux:badge>Total: <span x-text="Object.values($wire.rows).length"></span></flux:badge>
            @foreach(AttendanceStatus::cases() as $status)<flux:badge wire:key="summary-{{ $status->value }}">{{ $status->label() }}: <span x-text="Object.values($wire.rows).filter(row => row.status === '{{ $status->value }}').length"></span></flux:badge>@endforeach
        </div>
        <form wire:submit="save" class="grid gap-4">
            <div class="flex flex-wrap justify-between gap-3">
                <flux:button type="button" x-on:click="Object.entries($wire.roster).forEach(([id, student]) => { if (student.editable) $wire.rows[id].status = 'present' })">Mark All Present</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" :disabled="! collect($roster)->contains('editable', true)">Save Attendance</flux:button>
            </div>
            <x-app.panel title="Class register">
                <div class="hidden gap-4 border-b border-zinc-200 px-4 py-3 text-xs font-semibold uppercase text-zinc-500 lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(22rem,1.5fr)_minmax(10rem,1fr)] dark:border-zinc-800"><span>Student / Admission no.</span><span>Present / Absent / Late / Excused</span><span>Remark</span></div>
                <div class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @foreach($roster as $id => $student)
                        <article wire:key="attendance-{{ $academicYearId }}-{{ $termId }}-{{ $classLevelId }}-{{ $attendanceDate }}-{{ $id }}" class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(22rem,1.5fr)_minmax(10rem,1fr)] lg:items-center">
                            <div><p class="font-semibold">{{ $student['name'] }}</p><p class="font-mono text-xs text-zinc-500">{{ $student['admission_number'] }}</p>@if(! $student['editable'])<p class="text-xs text-zinc-500">Read only</p>@endif</div>
                            <fieldset @disabled(! $student['editable']) class="grid grid-cols-2 gap-2 sm:grid-cols-4"><legend class="sr-only">Attendance for {{ $student['name'] }}</legend>
                                @foreach(AttendanceStatus::cases() as $status)<label wire:key="status-{{ $id }}-{{ $status->value }}" class="flex cursor-pointer items-center gap-2 rounded-lg border border-zinc-200 p-2 text-sm has-checked:border-brand-600 has-checked:bg-brand-50 dark:border-zinc-700 dark:has-checked:bg-brand-950"><input type="radio" name="status-{{ $id }}" value="{{ $status->value }}" x-model="$wire.rows[{{ $id }}].status" class="size-4 accent-brand-600">{{ $status->label() }}</label>@endforeach
                            </fieldset>
                            <flux:input x-model="$wire.rows[{{ $id }}].remark" maxlength="500" placeholder="Optional remark" aria-label="Remark for {{ $student['name'] }}" :disabled="! $student['editable']" />
                        </article>
                    @endforeach
                </div>
            </x-app.panel>
        </form>
    @else
        <flux:callout>{{ $loadedSelection ? 'No enrolled students were found for this class and date.' : 'Select an academic year, term, class and date to load attendance.' }}</flux:callout>
    @endif
</div>
