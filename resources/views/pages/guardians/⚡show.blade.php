<?php

use App\Actions\Guardians\LinkGuardianToStudent;
use App\Actions\Guardians\SetPrimaryGuardian;
use App\Actions\Guardians\UnlinkGuardianFromStudent;
use App\GuardianRelationship;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Support\Authorization\Permissions;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Guardian Details')] class extends Component {
    #[Locked] public int $guardianId;
    public bool $showLinkForm = false;
    public string $studentSearch = '';
    public string $selectedStudentId = '';
    public string $relationship = '';
    public bool $isPrimary = false;

    public function mount(Guardian $guardian): void
    {
        Gate::authorize('view', $guardian);
        $this->guardianId = $guardian->id;
    }

    #[Computed]
    public function guardian(): Guardian
    {
        return Guardian::query()->select(['id', 'title', 'first_name', 'middle_name', 'last_name', 'phone', 'email', 'address'])
            ->with(['students' => fn ($query) => $query->select(['students.id', 'admission_number', 'first_name', 'middle_name', 'last_name'])->with([
                'currentEnrollment' => fn ($enrollmentQuery) => $enrollmentQuery->select(['enrollments.id', 'enrollments.student_id', 'enrollments.academic_year_id', 'enrollments.class_level_id', 'enrollments.status']),
                'currentEnrollment.academicYear:id,name',
                'currentEnrollment.classLevel:id,name',
            ])->orderBy('last_name')->orderBy('first_name')])
            ->findOrFail($this->guardianId);
    }

    /** @return Collection<int, Student> */
    #[Computed]
    public function studentResults(): Collection
    {
        if (! $this->showLinkForm || mb_strlen(trim($this->studentSearch)) < 2 || Gate::denies('linkStudent', $this->guardian)) {
            return collect();
        }

        $term = trim($this->studentSearch);

        return Student::query()->select(['id', 'admission_number', 'first_name', 'middle_name', 'last_name'])
            ->whereDoesntHave('guardians', fn (Builder $query): Builder => $query->whereKey($this->guardianId))
            ->where(fn (Builder $query): Builder => $query->where('admission_number', 'like', '%'.$term.'%')->orWhere('first_name', 'like', '%'.$term.'%')->orWhere('middle_name', 'like', '%'.$term.'%')->orWhere('last_name', 'like', '%'.$term.'%'))
            ->orderBy('last_name')->orderBy('first_name')->limit(8)->get();
    }

    public function openLinkForm(): void
    {
        Gate::authorize('linkStudent', $this->guardian);
        $this->reset('studentSearch', 'selectedStudentId', 'relationship', 'isPrimary');
        $this->resetValidation();
        $this->showLinkForm = true;
    }

    public function selectStudent(int $studentId): void
    {
        Gate::authorize('linkStudent', $this->guardian);
        $student = Student::query()->select(['id', 'admission_number', 'first_name', 'middle_name', 'last_name'])->findOrFail($studentId);
        abort_if($student->guardians()->whereKey($this->guardianId)->exists(), 422);
        $this->selectedStudentId = (string) $student->id;
        $this->studentSearch = $student->fullName().' · '.$student->admission_number;
    }

    public function link(LinkGuardianToStudent $linker): void
    {
        Gate::authorize('linkStudent', $this->guardian);
        $validated = $this->validate(['selectedStudentId' => ['required', 'integer', Rule::exists(Student::class, 'id')], 'relationship' => ['required', Rule::in(GuardianRelationship::labels())], 'isPrimary' => ['boolean']]);
        $student = Student::query()->findOrFail($validated['selectedStudentId']);
        $linker->handle($student, $this->guardian, $validated['relationship'], $validated['isPrimary']);
        $this->showLinkForm = false;
        unset($this->guardian, $this->studentResults);
        Flux::toast(variant: 'success', text: 'Student linked to guardian.');
    }

    public function setPrimary(int $studentGuardianId, SetPrimaryGuardian $setter): void
    {
        Gate::authorize('linkStudent', $this->guardian);
        $link = StudentGuardian::query()->whereKey($studentGuardianId)->where('guardian_id', $this->guardianId)->firstOrFail();
        $setter->handle($link);
        unset($this->guardian);
        Flux::toast(variant: 'success', text: 'Primary guardian updated.');
    }

    public function unlink(int $studentGuardianId, UnlinkGuardianFromStudent $unlinker): void
    {
        Gate::authorize('unlinkStudent', $this->guardian);
        $link = StudentGuardian::query()->whereKey($studentGuardianId)->where('guardian_id', $this->guardianId)->firstOrFail();
        $unlinker->handle($link);
        unset($this->guardian);
        Flux::toast(variant: 'success', text: 'Guardian unlinked from student.');
    }

    public function delete(): void
    {
        Gate::authorize('delete', $this->guardian);
        if ($this->guardian->students->isNotEmpty()) { $this->addError('delete', 'Unlink every student before deleting this guardian.'); return; }
        $this->guardian->delete();
        Flux::toast(variant: 'success', text: 'Guardian deleted.');
        $this->redirectRoute('guardians.index', navigate: true);
    }
};
?>

<div class="grid gap-5">
    @error('delete')<flux:callout variant="danger" icon="exclamation-triangle" :text="$message" />@enderror
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><flux:heading size="xl">Guardian Details</flux:heading><flux:text class="mt-1">{{ $this->guardian->fullName() }}</flux:text></div><div class="flex flex-wrap gap-2"><flux:button :href="route('guardians.index')" wire:navigate variant="ghost">Back</flux:button>@can(Permissions::GUARDIANS_UPDATE)<flux:button :href="route('guardians.edit', $this->guardian)" wire:navigate variant="primary" icon="pencil-square">Edit</flux:button>@endcan @can(Permissions::GUARDIANS_DELETE)<flux:button variant="danger" wire:click="delete" wire:confirm="Delete this unlinked guardian record?">Delete</flux:button>@endcan</div></div>
    <x-app.panel title="Contact information"><dl class="grid gap-5 p-5 sm:grid-cols-2 xl:grid-cols-4"><div><dt class="text-xs uppercase text-zinc-500">Name</dt><dd class="font-semibold">{{ $this->guardian->fullName() }}</dd></div><div><dt class="text-xs uppercase text-zinc-500">Phone</dt><dd><a class="hover:underline" href="tel:{{ $this->guardian->phone }}">{{ $this->guardian->phone }}</a></dd></div><div><dt class="text-xs uppercase text-zinc-500">Email</dt><dd>@if($this->guardian->email)<a class="hover:underline" href="mailto:{{ $this->guardian->email }}">{{ $this->guardian->email }}</a>@else—@endif</dd></div><div><dt class="text-xs uppercase text-zinc-500">Address</dt><dd>{{ $this->guardian->address ?? '—' }}</dd></div></dl></x-app.panel>
    <x-app.panel title="Linked students"><x-slot:action>@can(Permissions::GUARDIANS_LINK_STUDENT)<flux:button size="sm" variant="primary" icon="plus" wire:click="openLinkForm">Link Student</flux:button>@endcan</x-slot:action><div class="overflow-x-auto"><table class="w-full min-w-4xl text-left text-sm"><thead class="border-b text-xs uppercase text-zinc-500"><tr><th class="px-4 py-3">Student</th><th class="px-4 py-3">Admission no.</th><th class="px-4 py-3">Current class</th><th class="px-4 py-3">Relationship</th><th class="px-4 py-3">Primary</th><th class="px-4 py-3 text-right">Actions</th></tr></thead><tbody class="divide-y dark:divide-zinc-800">
        @forelse($this->guardian->students as $student)<tr wire:key="linked-student-{{ $student->id }}"><td class="px-4 py-3 font-semibold">{{ $student->fullName() }}</td><td class="px-4 py-3 font-mono text-xs">{{ $student->admission_number }}</td><td class="px-4 py-3">@if($student->currentEnrollment)<span>{{ $student->currentEnrollment->classLevel->name }}</span><span class="block text-xs text-zinc-500">{{ $student->currentEnrollment->academicYear->name }}</span>@else<span class="text-zinc-500">Not currently enrolled</span>@endif</td><td class="px-4 py-3">{{ $student->pivot->relationship ?? $this->guardian->relationship ?? '—' }}</td><td class="px-4 py-3">@if($student->pivot->is_primary)<flux:badge color="blue">Primary</flux:badge>@else<span class="text-zinc-500">No</span>@endif</td><td class="px-4 py-3"><div class="flex justify-end gap-2">@can(Permissions::STUDENTS_VIEW)<flux:button size="sm" variant="ghost" :href="route('students.show', $student)" wire:navigate>View</flux:button>@endcan @can(Permissions::GUARDIANS_LINK_STUDENT)@if(! $student->pivot->is_primary)<flux:button size="sm" variant="ghost" wire:click="setPrimary({{ $student->pivot->id }})">Set primary</flux:button>@endif @endcan @can(Permissions::GUARDIANS_UNLINK_STUDENT)<flux:button size="sm" variant="ghost" wire:click="unlink({{ $student->pivot->id }})" wire:confirm="Unlink this guardian from {{ $student->fullName() }}?">Unlink</flux:button>@endcan</div></td></tr>
        @empty<tr><td colspan="6" class="px-4 py-12 text-center text-zinc-500">No students are linked to this guardian.</td></tr>@endforelse
    </tbody></table></div></x-app.panel>
    <flux:modal wire:model.self="showLinkForm" class="max-w-xl"><form wire:submit="link" class="grid gap-5"><div><flux:heading size="lg">Link Student</flux:heading><flux:text class="mt-1">Search without loading the full student directory.</flux:text></div><flux:input wire:model.live.debounce.400ms="studentSearch" label="Find student" icon="magnifying-glass" placeholder="Name or admission number" />@if($this->studentResults->isNotEmpty())<div class="grid max-h-56 gap-1 overflow-y-auto rounded-lg border border-zinc-200 p-2 dark:border-zinc-700">@foreach($this->studentResults as $student)<button type="button" wire:key="student-result-{{ $student->id }}" wire:click="selectStudent({{ $student->id }})" class="rounded-md p-2 text-left hover:bg-zinc-100 dark:hover:bg-zinc-800"><span class="font-medium">{{ $student->fullName() }}</span><span class="block text-xs text-zinc-500">{{ $student->admission_number }}</span></button>@endforeach</div>@endif @error('selectedStudentId')<p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror<flux:select wire:model="relationship" label="Relationship" required><option value="">Select relationship</option>@foreach(GuardianRelationship::cases() as $option)<option value="{{ $option->label() }}">{{ $option->label() }}</option>@endforeach</flux:select><flux:switch wire:model="isPrimary" label="Primary guardian for this student" /><div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="link">Link Student</flux:button></div></form></flux:modal>
</div>
