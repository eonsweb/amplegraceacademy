<?php

use App\Actions\Guardians\CreateGuardianForStudent;
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
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked] public int $studentId;
    public bool $showForm = false;
    public string $mode = 'existing';
    public string $search = '';
    public string $selectedGuardianId = '';
    public string $relationship = '';
    public bool $isPrimary = false;
    public string $title = '';
    public string $firstName = '';
    public string $middleName = '';
    public string $lastName = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';

    public function mount(int $studentId): void
    {
        Gate::authorize(Permissions::STUDENTS_VIEW);
        $this->studentId = Student::query()->findOrFail($studentId)->id;
    }

    /** @return Collection<int, Guardian> */
    #[Computed]
    public function guardians(): Collection
    {
        return Guardian::query()->select(['guardians.id', 'title', 'first_name', 'middle_name', 'last_name', 'relationship', 'phone', 'email'])
            ->whereHas('students', fn (Builder $query): Builder => $query->whereKey($this->studentId))
            ->withWhereHas('studentGuardians', fn ($query) => $query->where('student_id', $this->studentId))
            ->orderBy('last_name')->orderBy('first_name')->get()->sortByDesc(fn (Guardian $guardian): bool => $guardian->studentGuardians->first()->is_primary)->values();
    }

    /** @return Collection<int, Guardian> */
    #[Computed]
    public function guardianResults(): Collection
    {
        if (! $this->showForm || $this->mode !== 'existing' || mb_strlen(trim($this->search)) < 2 || Gate::denies(Permissions::GUARDIANS_LINK_STUDENT)) { return collect(); }
        $term = trim($this->search);

        return Guardian::query()->select(['id', 'first_name', 'middle_name', 'last_name', 'phone', 'email'])
            ->whereDoesntHave('students', fn (Builder $query): Builder => $query->whereKey($this->studentId))
            ->where(fn (Builder $query): Builder => $query->where('first_name', 'like', '%'.$term.'%')->orWhere('middle_name', 'like', '%'.$term.'%')->orWhere('last_name', 'like', '%'.$term.'%')->orWhere('phone', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%'))
            ->orderBy('last_name')->orderBy('first_name')->limit(8)->get();
    }

    public function openForm(string $mode): void
    {
        abort_unless(in_array($mode, ['existing', 'new'], true), 404);
        Gate::authorize(Permissions::GUARDIANS_LINK_STUDENT);
        Gate::authorize($mode === 'new' ? Permissions::GUARDIANS_CREATE : Permissions::GUARDIANS_VIEW);
        $this->resetForm();
        $this->mode = $mode;
        $this->showForm = true;
    }

    public function selectGuardian(int $guardianId): void
    {
        Gate::authorize(Permissions::GUARDIANS_LINK_STUDENT);
        Gate::authorize(Permissions::GUARDIANS_VIEW);
        $guardian = Guardian::query()->select(['id', 'first_name', 'middle_name', 'last_name', 'phone'])->findOrFail($guardianId);
        abort_if($guardian->students()->whereKey($this->studentId)->exists(), 422);
        $this->selectedGuardianId = (string) $guardian->id;
        $this->search = $guardian->fullName().' · '.$guardian->phone;
    }

    public function save(LinkGuardianToStudent $linker, CreateGuardianForStudent $creator): void
    {
        $student = Student::query()->findOrFail($this->studentId);
        Gate::authorize(Permissions::GUARDIANS_LINK_STUDENT);
        $base = $this->validate(['mode' => ['required', Rule::in(['existing', 'new'])], 'relationship' => ['required', Rule::in(GuardianRelationship::labels())], 'isPrimary' => ['boolean']]);

        if ($base['mode'] === 'existing') {
            Gate::authorize(Permissions::GUARDIANS_VIEW);
            $selected = $this->validate(['selectedGuardianId' => ['required', 'integer', Rule::exists(Guardian::class, 'id')]]);
            $guardian = Guardian::query()->findOrFail($selected['selectedGuardianId']);
            $linker->handle($student, $guardian, $base['relationship'], $base['isPrimary']);
        } else {
            Gate::authorize(Permissions::GUARDIANS_CREATE);
            $this->phone = trim($this->phone);
            $this->email = trim($this->email);
            $validated = $this->validate(['title' => ['nullable', 'string', 'max:30'], 'firstName' => ['required', 'string', 'max:255'], 'middleName' => ['nullable', 'string', 'max:255'], 'lastName' => ['required', 'string', 'max:255'], 'phone' => ['required', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string', 'max:2000']]);
            $phone = trim($validated['phone']);
            $email = filled($validated['email']) ? str($validated['email'])->trim()->lower()->toString() : null;
            $duplicate = Guardian::query()->select(['id', 'first_name', 'middle_name', 'last_name'])->where(function (Builder $query) use ($phone, $email): void { $query->where('phone', $phone)->when($email !== null, fn (Builder $query): Builder => $query->orWhere('email', $email)); })->first();
            if ($duplicate !== null) { throw ValidationException::withMessages(['phone' => 'A matching guardian may already exist: '.$duplicate->fullName().'. Use Link Existing Guardian instead.']); }
            $creator->handle($student, ['title' => filled($validated['title']) ? trim($validated['title']) : null, 'first_name' => trim($validated['firstName']), 'middle_name' => filled($validated['middleName']) ? trim($validated['middleName']) : null, 'last_name' => trim($validated['lastName']), 'relationship' => null, 'phone' => $phone, 'email' => $email, 'address' => filled($validated['address']) ? trim($validated['address']) : null], $base['relationship'], $base['isPrimary']);
        }

        $this->showForm = false;
        $this->resetForm();
        unset($this->guardians, $this->guardianResults);
        Flux::toast(variant: 'success', text: 'Guardian linked to student.');
    }

    public function setPrimary(int $studentGuardianId, SetPrimaryGuardian $setter): void
    {
        Gate::authorize(Permissions::GUARDIANS_LINK_STUDENT);
        $link = StudentGuardian::query()->whereKey($studentGuardianId)->where('student_id', $this->studentId)->firstOrFail();
        $setter->handle($link);
        unset($this->guardians);
        Flux::toast(variant: 'success', text: 'Primary guardian updated.');
    }

    public function unlink(int $studentGuardianId, UnlinkGuardianFromStudent $unlinker): void
    {
        Gate::authorize(Permissions::GUARDIANS_UNLINK_STUDENT);
        $link = StudentGuardian::query()->whereKey($studentGuardianId)->where('student_id', $this->studentId)->firstOrFail();
        $unlinker->handle($link);
        unset($this->guardians);
        Flux::toast(variant: 'success', text: 'Guardian unlinked from student.');
    }

    private function resetForm(): void
    {
        $this->reset('search', 'selectedGuardianId', 'relationship', 'isPrimary', 'title', 'firstName', 'middleName', 'lastName', 'phone', 'email', 'address');
        $this->resetValidation();
    }
};
?>

<x-app.panel title="Guardians / Parents">
    <x-slot:action><div class="flex flex-wrap gap-2">@can(Permissions::GUARDIANS_LINK_STUDENT)@can(Permissions::GUARDIANS_VIEW)<flux:button size="sm" variant="ghost" wire:click="openForm('existing')">Link Existing</flux:button>@endcan @can(Permissions::GUARDIANS_CREATE)<flux:button size="sm" variant="primary" icon="plus" wire:click="openForm('new')">Add New Guardian</flux:button>@endcan @endcan</div></x-slot:action>
    <div class="grid gap-4 p-4 md:grid-cols-2">
        @forelse($this->guardians as $guardian) @php($link = $guardian->studentGuardians->first())
            <article wire:key="student-guardian-{{ $guardian->id }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700"><div class="flex items-start justify-between gap-3"><div>@can(Permissions::GUARDIANS_VIEW)<a class="font-semibold hover:underline" href="{{ route('guardians.show', $guardian) }}" wire:navigate>{{ $guardian->fullName() }}</a>@else<p class="font-semibold">{{ $guardian->fullName() }}</p>@endcan<p class="text-sm text-zinc-500">{{ $link->relationship ?? $guardian->relationship ?? 'Guardian' }}</p></div>@if($link->is_primary)<flux:badge color="blue">Primary</flux:badge>@endif</div><dl class="mt-3 grid gap-2 text-sm"><div><dt class="text-zinc-500">Phone</dt><dd><a class="hover:underline" href="tel:{{ $guardian->phone }}">{{ $guardian->phone }}</a></dd></div><div><dt class="text-zinc-500">Email</dt><dd>@if($guardian->email)<a class="hover:underline" href="mailto:{{ $guardian->email }}">{{ $guardian->email }}</a>@else—@endif</dd></div></dl><div class="mt-4 flex flex-wrap justify-end gap-2">@can(Permissions::GUARDIANS_LINK_STUDENT)@if(! $link->is_primary)<flux:button size="sm" variant="ghost" wire:click="setPrimary({{ $link->id }})">Set primary</flux:button>@endif @endcan @can(Permissions::GUARDIANS_UNLINK_STUDENT)<flux:button size="sm" variant="ghost" wire:click="unlink({{ $link->id }})" wire:confirm="Unlink {{ $guardian->fullName() }} from this student?">Unlink</flux:button>@endcan</div></article>
        @empty<div class="md:col-span-2 py-8 text-center text-sm text-zinc-500">No guardians are linked to this student.</div>@endforelse
    </div>
    <flux:modal wire:model.self="showForm" class="max-w-2xl"><form wire:submit="save" class="grid gap-5"><div><flux:heading size="lg">{{ $mode === 'existing' ? 'Link Existing Guardian' : 'Add New Guardian' }}</flux:heading><flux:text class="mt-1">{{ $mode === 'existing' ? 'Search by name, phone, or email.' : 'Create and link the guardian in one step.' }}</flux:text></div>
        @if($mode === 'existing')<flux:input wire:model.live.debounce.400ms="search" label="Find guardian" icon="magnifying-glass" placeholder="Name, phone, or email" />@if($this->guardianResults->isNotEmpty())<div class="grid max-h-56 gap-1 overflow-y-auto rounded-lg border border-zinc-200 p-2 dark:border-zinc-700">@foreach($this->guardianResults as $result)<button type="button" wire:key="guardian-result-{{ $result->id }}" wire:click="selectGuardian({{ $result->id }})" class="rounded-md p-2 text-left hover:bg-zinc-100 dark:hover:bg-zinc-800"><span class="font-medium">{{ $result->fullName() }}</span><span class="block text-xs text-zinc-500">{{ $result->phone }}{{ $result->email ? ' · '.$result->email : '' }}</span></button>@endforeach</div>@endif @error('selectedGuardianId')<p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
        @else<div class="grid gap-4 md:grid-cols-2"><flux:input wire:model="title" label="Title" /><flux:input wire:model="firstName" label="First name" required /><flux:input wire:model="middleName" label="Middle name" /><flux:input wire:model="lastName" label="Last name" required /><flux:input wire:model="phone" type="tel" label="Phone" required /><flux:input wire:model="email" type="email" label="Email" /><flux:textarea class="md:col-span-2" wire:model="address" label="Address" rows="2" /></div>@endif
        <div class="grid gap-4 sm:grid-cols-2"><flux:select wire:model="relationship" label="Relationship" required><option value="">Select relationship</option>@foreach(GuardianRelationship::cases() as $option)<option value="{{ $option->label() }}">{{ $option->label() }}</option>@endforeach</flux:select><flux:switch wire:model="isPrimary" label="Primary guardian" /></div><div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Save</flux:button></div>
    </form></flux:modal>
</x-app.panel>
