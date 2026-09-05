<?php

use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Student Profile')] class extends Component {
    #[Locked]
    public int $studentId;

    public function mount(Student $student): void
    {
        Gate::authorize(Permissions::STUDENTS_VIEW);
        $this->studentId = $student->id;
    }

    #[Computed]
    public function student(): Student
    {
        return Student::query()->select(['id', 'admission_number', 'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth', 'photo', 'status'])
            ->with([
                'enrollments' => fn ($query) => $query->select(['id', 'student_id', 'academic_year_id', 'class_level_id', 'enrollment_date', 'status'])->with(['academicYear:id,name', 'classLevel:id,name'])->orderByDesc('enrollment_date')->orderByDesc('id'),
            ])->findOrFail($this->studentId);
    }
};
?>

@php($currentEnrollment = $this->student->enrollments->first(fn ($enrollment) => $enrollment->status->value === 'active'))
<div class="grid gap-5">
    @if(session('student-created'))<flux:callout variant="success" icon="check-circle" heading="Student created successfully" text="{{ session('student-created') }}" />@endif
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><flux:heading size="xl">Student Profile</flux:heading><flux:text class="mt-1">{{ $this->student->admission_number }}</flux:text></div><div class="flex gap-2"><flux:button :href="route('students.index')" wire:navigate variant="ghost">Back</flux:button>@can(Permissions::STUDENTS_UPDATE)<flux:button :href="route('students.edit', $this->student)" wire:navigate variant="primary" icon="pencil-square">Edit profile</flux:button>@endcan</div></div>
    <div class="grid gap-5 xl:grid-cols-3">
        <x-app.panel title="Personal information" class="xl:col-span-2"><div class="grid gap-5 p-5 sm:grid-cols-[auto_1fr]">@if($this->student->photoUrl())<img src="{{ $this->student->photoUrl() }}" alt="{{ $this->student->fullName() }}" class="size-32 rounded-xl object-cover">@else<div class="grid size-32 place-items-center rounded-xl bg-zinc-100 text-3xl font-semibold text-zinc-500 dark:bg-zinc-800">{{ str($this->student->first_name)->substr(0, 1) }}{{ str($this->student->last_name)->substr(0, 1) }}</div>@endif<div class="grid gap-4 sm:grid-cols-2"><div><p class="text-xs uppercase text-zinc-500">Full name</p><p class="font-semibold">{{ $this->student->fullName() }}</p></div><div><p class="text-xs uppercase text-zinc-500">Status</p><flux:badge :color="$this->student->status->value === 'active' ? 'green' : 'zinc'">{{ $this->student->status->label() }}</flux:badge></div><div><p class="text-xs uppercase text-zinc-500">Gender</p><p>{{ $this->student->gender->label() }}</p></div><div><p class="text-xs uppercase text-zinc-500">Date of birth</p><p>{{ $this->student->date_of_birth?->format('j M Y') ?? '—' }}</p></div><div><p class="text-xs uppercase text-zinc-500">Age</p><p>{{ $this->student->age() === null ? '—' : $this->student->age().' '.str('year')->plural($this->student->age()) }}</p></div><div><p class="text-xs uppercase text-zinc-500">Current class</p><p>{{ $currentEnrollment?->classLevel->name ?? 'Not currently enrolled' }}</p></div><div><p class="text-xs uppercase text-zinc-500">Academic year</p><p>{{ $currentEnrollment?->academicYear->name ?? '—' }}</p></div></div></div></x-app.panel>
        <x-app.panel title="Module extensions"><div class="grid gap-3 p-4 text-sm text-zinc-500"><p>Attendance summary will appear here.</p><p>Fees and payment standing will appear here.</p><p>Assessment performance will appear here.</p></div></x-app.panel>
    </div>
    <livewire:student-guardians :student-id="$this->student->id" />
    <x-app.panel title="Enrollment history"><div class="overflow-x-auto"><table class="w-full min-w-2xl text-left text-sm"><thead class="border-b text-xs uppercase text-zinc-500"><tr><th class="px-4 py-3">Academic year</th><th class="px-4 py-3">Class</th><th class="px-4 py-3">Enrollment date</th><th class="px-4 py-3">Status</th></tr></thead><tbody class="divide-y dark:divide-zinc-800">@foreach($this->student->enrollments as $enrollment)<tr><td class="px-4 py-3">{{ $enrollment->academicYear->name }}</td><td class="px-4 py-3">{{ $enrollment->classLevel->name }}</td><td class="px-4 py-3">{{ $enrollment->enrollment_date->format('j M Y') }}</td><td class="px-4 py-3"><flux:badge>{{ $enrollment->status->label() }}</flux:badge></td></tr>@endforeach</tbody></table></div></x-app.panel>
</div>
