<?php

use App\Gender;
use App\Models\Guardian;
use App\Models\Student;
use App\StudentStatus;
use App\Support\Authorization\Permissions;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

new #[Title('Edit Student')] class extends Component {
    use WithFileUploads;

    #[Locked] public int $studentId;
    public string $admissionNumber = '';
    public string $firstName = '';
    public string $middleName = '';
    public string $lastName = '';
    public string $gender = '';
    public string $dateOfBirth = '';
    public mixed $photo = null;
    public string $status = '';
    /** @var array<int, array<string, mixed>> */ public array $guardians = [];
    public int $primaryGuardianKey = 0;
    public int $guardianKeyCounter = 0;
    /** @var array<int, string> */ public array $guardianSearch = [];
    /** @var array<int, list<array{id: int, label: string, detail: string}>> */ public array $guardianResults = [];
    /** @var array<int, int> */
    #[Locked]
    public array $attachedGuardianIds = [];

    public function mount(Student $student): void
    {
        Gate::authorize(Permissions::STUDENTS_UPDATE);
        $student->load('guardians');
        $this->studentId = $student->id; $this->admissionNumber = $student->admission_number; $this->firstName = $student->first_name; $this->middleName = $student->middle_name ?? ''; $this->lastName = $student->last_name; $this->gender = $student->gender->value; $this->dateOfBirth = $student->date_of_birth?->toDateString() ?? ''; $this->status = $student->status->value;
        foreach ($student->guardians as $guardian) {
            $key = $this->guardianKeyCounter++;
            $this->guardians[$key] = ['mode' => 'attached', 'guardian_id' => $guardian->id, 'label' => $guardian->fullName(), 'title' => $guardian->title ?? '', 'first_name' => $guardian->first_name, 'middle_name' => $guardian->middle_name ?? '', 'last_name' => $guardian->last_name, 'relationship' => $guardian->pivot->relationship ?? $guardian->relationship ?? '', 'phone' => $guardian->phone, 'email' => $guardian->email ?? '', 'address' => $guardian->address ?? ''];
            $this->attachedGuardianIds[$key] = $guardian->id;
            if ($guardian->pivot->is_primary) { $this->primaryGuardianKey = $key; }
        }
    }

    public function addGuardian(): void
    {
        if (count($this->guardians) >= 5) { return; }
        $key = $this->guardianKeyCounter++;
        $this->guardians[$key] = $this->emptyGuardian();
    }

    public function removeGuardian(int $key): void
    {
        if (($this->guardians[$key]['mode'] ?? 'attached') === 'attached' || count($this->guardians) === 1) { return; }
        unset($this->guardians[$key], $this->guardianSearch[$key], $this->guardianResults[$key]);
        if ($this->primaryGuardianKey === $key) { $this->primaryGuardianKey = (int) array_key_first($this->guardians); }
    }

    public function searchGuardians(int $key): void
    {
        Gate::authorize(Permissions::GUARDIANS_VIEW);
        $term = trim($this->guardianSearch[$key] ?? '');
        if (mb_strlen($term) < 2) { $this->addError("guardianSearch.$key", 'Enter at least 2 characters.'); return; }
        $attachedIds = collect($this->guardians)->pluck('guardian_id')->filter()->all();
        $this->guardianResults[$key] = Guardian::query()->select(['id', 'first_name', 'middle_name', 'last_name', 'phone', 'email'])->whereNotIn('id', $attachedIds)->where(function (Builder $query) use ($term): void { $query->where('first_name', 'like', '%'.$term.'%')->orWhere('middle_name', 'like', '%'.$term.'%')->orWhere('last_name', 'like', '%'.$term.'%')->orWhere('phone', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%'); })->orderBy('last_name')->limit(8)->get()->map(fn (Guardian $guardian): array => ['id' => $guardian->id, 'label' => $guardian->fullName(), 'detail' => $guardian->phone.($guardian->email ? ' · '.$guardian->email : '')])->all();
    }

    public function chooseGuardian(int $key, int $guardianId): void
    {
        Gate::authorize(Permissions::GUARDIANS_VIEW);
        abort_unless(array_key_exists($key, $this->guardians), 404);
        abort_if(collect($this->guardians)->pluck('guardian_id')->contains($guardianId), 422);
        $guardian = Guardian::query()->findOrFail($guardianId);
        $this->guardians[$key] = ['mode' => 'existing', 'guardian_id' => $guardian->id, 'label' => $guardian->fullName(), 'title' => '', 'first_name' => '', 'middle_name' => '', 'last_name' => '', 'relationship' => $guardian->relationship ?? 'Legal Guardian', 'phone' => '', 'email' => '', 'address' => ''];
        $this->guardianResults[$key] = [];
    }

    public function useNewGuardian(int $key): void
    {
        if (($this->guardians[$key]['mode'] ?? '') === 'attached') { return; }
        $this->guardians[$key] = $this->emptyGuardian();
    }

    public function save(): void
    {
        Gate::authorize(Permissions::STUDENTS_UPDATE);
        foreach ($this->guardians as $row) { Gate::authorize(match ($row['mode'] ?? 'new') { 'new' => Permissions::GUARDIANS_CREATE, 'attached' => Permissions::GUARDIANS_UPDATE, default => Permissions::GUARDIANS_VIEW }); }
        $rules = ['firstName' => ['required', 'string', 'max:255'], 'middleName' => ['nullable', 'string', 'max:255'], 'lastName' => ['required', 'string', 'max:255'], 'gender' => ['required', Rule::enum(Gender::class)], 'dateOfBirth' => ['nullable', 'date', 'before_or_equal:today'], 'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'], 'status' => ['required', Rule::enum(StudentStatus::class)], 'guardians' => ['required', 'array', 'min:1', 'max:5'], 'primaryGuardianKey' => ['required', Rule::in(array_keys($this->guardians))]];
        foreach ($this->guardians as $key => $row) {
            $rules["guardians.$key.mode"] = ['required', Rule::in(['new', 'existing', 'attached'])];
            if (($row['mode'] ?? '') === 'existing') { $rules["guardians.$key.guardian_id"] = ['required', 'integer', 'distinct', Rule::exists(Guardian::class, 'id')]; $rules["guardians.$key.relationship"] = ['required', 'string', 'max:80']; continue; }
            $rules["guardians.$key.guardian_id"] = [($row['mode'] ?? '') === 'attached' ? 'required' : 'nullable', 'integer', 'distinct', Rule::in(isset($this->attachedGuardianIds[$key]) ? [$this->attachedGuardianIds[$key]] : [])];
            foreach (['first_name', 'last_name', 'relationship', 'phone'] as $field) { $rules["guardians.$key.$field"] = ['required', 'string', 'max:255']; }
            foreach (['title', 'middle_name'] as $field) { $rules["guardians.$key.$field"] = ['nullable', 'string', 'max:255']; }
            $rules["guardians.$key.email"] = ['nullable', 'email', 'max:255']; $rules["guardians.$key.address"] = ['nullable', 'string', 'max:2000'];
        }
        foreach ($this->attachedGuardianIds as $key => $guardianId) {
            $rules["guardians.$key.mode"] = ['required', Rule::in(['attached'])];
            $rules["guardians.$key.guardian_id"] = ['required', 'integer', 'distinct', Rule::in([$guardianId])];
        }
        $validated = $this->validate($rules);
        $student = Student::query()->findOrFail($this->studentId);
        $oldPhoto = $student->photo;
        $storedPhoto = $this->photo?->store(path: 'students', options: 'public');

        try {
            DB::transaction(function () use ($student, $storedPhoto, $validated): void {
                $student->update(['first_name' => trim($validated['firstName']), 'middle_name' => filled($validated['middleName']) ? trim($validated['middleName']) : null, 'last_name' => trim($validated['lastName']), 'gender' => $validated['gender'], 'date_of_birth' => $validated['dateOfBirth'] ?: null, 'status' => $validated['status'], ...($storedPhoto ? ['photo' => $storedPhoto] : [])]);
                foreach ($validated['guardians'] as $key => $row) {
                    if ($row['mode'] === 'new') { $guardian = Guardian::query()->create($this->guardianData($row)); $student->guardians()->attach($guardian, ['relationship' => $row['relationship'], 'is_primary' => (int) $key === $validated['primaryGuardianKey']]); continue; }
                    $guardian = Guardian::query()->findOrFail($row['guardian_id']);
                    if ($row['mode'] === 'attached') {
                        $guardian->update(collect($this->guardianData($row))->except('relationship')->all());
                        $student->guardians()->updateExistingPivot($guardian->id, ['relationship' => $row['relationship'], 'is_primary' => (int) $key === $validated['primaryGuardianKey']]);
                    } else {
                        $student->guardians()->attach($guardian, ['relationship' => $row['relationship'], 'is_primary' => (int) $key === $validated['primaryGuardianKey']]);
                    }
                }
            });
        } catch (Throwable $exception) {
            if ($storedPhoto) { Storage::disk('public')->delete($storedPhoto); }
            throw $exception;
        }
        if ($storedPhoto && $oldPhoto) { Storage::disk('public')->delete($oldPhoto); }
        Flux::toast(variant: 'success', text: 'Student profile updated.');
        $this->redirectRoute('students.show', $student, navigate: true);
    }

    /** @return array<string, mixed> */
    private function guardianData(array $row): array
    {
        return collect($row)->only(['title', 'first_name', 'middle_name', 'last_name', 'relationship', 'phone', 'email', 'address'])->map(fn ($value) => is_string($value) ? (filled($value) ? trim($value) : null) : $value)->all();
    }

    /** @return array<string, mixed> */
    private function emptyGuardian(): array
    {
        return ['mode' => 'new', 'guardian_id' => null, 'label' => '', 'title' => '', 'first_name' => '', 'middle_name' => '', 'last_name' => '', 'relationship' => '', 'phone' => '', 'email' => '', 'address' => ''];
    }
};
?>

<form wire:submit="save" class="grid gap-5">
    <div class="flex items-center justify-between"><div><flux:heading size="xl">Edit Student</flux:heading><flux:text class="mt-1">Admission number {{ $admissionNumber }} is permanent. Placement history is managed separately.</flux:text></div><flux:button :href="route('students.show', $studentId)" wire:navigate variant="ghost">Cancel</flux:button></div>
    <x-app.panel title="Personal information"><div class="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-3"><flux:input :value="$admissionNumber" label="Admission number" disabled /><flux:input wire:model="firstName" label="First name" required autofocus /><flux:input wire:model="middleName" label="Middle name" /><flux:input wire:model="lastName" label="Last name" required /><flux:select wire:model="gender" label="Gender">@foreach(Gender::cases() as $item)<option value="{{ $item->value }}">{{ $item->label() }}</option>@endforeach</flux:select><x-app.student-date-of-birth class="xl:col-span-2" model="dateOfBirth" :value="$dateOfBirth" /><flux:select wire:model="status" label="Status">@foreach(StudentStatus::cases() as $item)<option value="{{ $item->value }}">{{ $item->label() }}</option>@endforeach</flux:select><flux:input wire:model="photo" type="file" label="Replace photo" accept="image/jpeg,image/png,image/webp" /></div></x-app.panel>
    <x-app.panel title="Guardians"><div class="grid gap-4 p-4">@foreach($guardians as $key => $guardian)<section wire:key="guardian-edit-{{ $key }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700"><div class="mb-4 flex items-center justify-between"><label class="flex items-center gap-2 text-sm font-semibold"><input type="radio" wire:model="primaryGuardianKey" value="{{ $key }}"> Primary guardian</label>@if($guardian['mode'] !== 'attached')<flux:button type="button" size="sm" variant="ghost" wire:click="removeGuardian({{ $key }})" icon="trash">Remove</flux:button>@endif</div>@if($guardian['mode'] === 'existing')<div class="flex items-center justify-between rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800"><div><p class="font-semibold">{{ $guardian['label'] }}</p><p class="text-xs text-zinc-500">Existing guardian record</p></div><flux:button type="button" size="sm" variant="ghost" wire:click="useNewGuardian({{ $key }})">Use new</flux:button></div>@else @if($guardian['mode'] === 'new')<div class="mb-4 grid gap-2 sm:grid-cols-[1fr_auto]"><flux:input wire:model="guardianSearch.{{ $key }}" placeholder="Find existing guardian" /><flux:button type="button" wire:click="searchGuardians({{ $key }})">Search</flux:button></div>@if(filled($guardianResults[$key] ?? []))<div class="mb-4 grid gap-2 rounded-lg border p-2">@foreach($guardianResults[$key] as $result)<button type="button" wire:click="chooseGuardian({{ $key }}, {{ $result['id'] }})" class="rounded-md p-2 text-left hover:bg-zinc-100 dark:hover:bg-zinc-800"><span class="font-medium">{{ $result['label'] }}</span><span class="ml-2 text-xs text-zinc-500">{{ $result['detail'] }}</span></button>@endforeach</div>@endif @endif<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"><flux:input wire:model="guardians.{{ $key }}.title" label="Title" /><flux:input wire:model="guardians.{{ $key }}.first_name" label="First name" required /><flux:input wire:model="guardians.{{ $key }}.middle_name" label="Middle name" /><flux:input wire:model="guardians.{{ $key }}.last_name" label="Last name" required /><flux:input wire:model="guardians.{{ $key }}.relationship" label="Relationship" required /><flux:input wire:model="guardians.{{ $key }}.phone" label="Phone" required /><flux:input wire:model="guardians.{{ $key }}.email" type="email" label="Email" /><flux:input wire:model="guardians.{{ $key }}.address" label="Address" /></div>@endif</section>@endforeach<flux:button type="button" class="justify-self-start" variant="ghost" icon="plus" wire:click="addGuardian" :disabled="count($guardians) >= 5">Add guardian</flux:button></div></x-app.panel>
    <div class="flex justify-end"><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save,photo">Save Changes</flux:button></div>
</form>
