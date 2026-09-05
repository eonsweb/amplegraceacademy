<?php

use App\Actions\Students\CreateStudent;
use App\EnrollmentStatus;
use App\Gender;
use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\Guardian;
use App\StudentStatus;
use App\Support\Authorization\Permissions;
use App\Support\Settings\SystemSettings;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

new #[Title('Add Student')] class extends Component {
    use WithFileUploads;

    #[Locked]
    public string $admissionNumberPreview = '';
    public string $firstName = '';
    public string $middleName = '';
    public string $lastName = '';
    public string $gender = '';
    public string $dateOfBirth = '';
    public mixed $photo = null;
    public string $status = 'active';
    public string $academicYearId = '';
    public string $classLevelId = '';
    public string $enrollmentDate = '';
    public string $enrollmentStatus = 'active';
    /** @var array<int, array<string, mixed>> */
    public array $guardians = [];
    public int $primaryGuardianKey = 0;
    public int $guardianKeyCounter = 0;
    /** @var array<int, string> */
    public array $guardianSearch = [];
    /** @var array<int, list<array{id: int, label: string, detail: string}>> */
    public array $guardianResults = [];

    public function mount(SystemSettings $settings): void
    {
        Gate::authorize(Permissions::STUDENTS_CREATE);
        $this->admissionNumberPreview = $settings->schoolInitials() === null
            ? 'School initials not configured'
            : sprintf('%s/%d/XXXX', $settings->schoolInitials(), now()->year);
        $this->academicYearId = (string) (AcademicYear::query()->where('is_current', true)->value('id') ?? '');
        $this->classLevelId = (string) (ClassLevel::query()->where('is_active', true)->orderBy('level_order')->value('id') ?? '');
        $this->enrollmentDate = now()->toDateString();
        $this->addGuardian();
    }

    public function addGuardian(): void
    {
        if (count($this->guardians) >= 5) { return; }
        $key = $this->guardianKeyCounter++;
        $this->guardians[$key] = $this->emptyGuardian();
        if (count($this->guardians) === 1) { $this->primaryGuardianKey = $key; }
    }

    public function removeGuardian(int $key): void
    {
        if (count($this->guardians) === 1 || ! array_key_exists($key, $this->guardians)) { return; }
        unset($this->guardians[$key], $this->guardianSearch[$key], $this->guardianResults[$key]);
        if ($this->primaryGuardianKey === $key) { $this->primaryGuardianKey = (int) array_key_first($this->guardians); }
    }

    public function useNewGuardian(int $key): void
    {
        $this->guardians[$key] = $this->emptyGuardian();
        $this->guardianResults[$key] = [];
    }

    public function searchGuardians(int $key): void
    {
        Gate::authorize(Permissions::GUARDIANS_VIEW);
        $term = trim($this->guardianSearch[$key] ?? '');
        if (mb_strlen($term) < 2) { $this->addError("guardianSearch.$key", 'Enter at least 2 characters.'); return; }

        $this->guardianResults[$key] = Guardian::query()->select(['id', 'first_name', 'middle_name', 'last_name', 'phone', 'email'])
            ->where(function (Builder $query) use ($term): void {
                $query->where('first_name', 'like', '%'.$term.'%')->orWhere('middle_name', 'like', '%'.$term.'%')->orWhere('last_name', 'like', '%'.$term.'%')->orWhere('phone', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%');
            })->orderBy('last_name')->limit(8)->get()->map(fn (Guardian $guardian): array => ['id' => $guardian->id, 'label' => $guardian->fullName(), 'detail' => $guardian->phone.($guardian->email ? ' · '.$guardian->email : '')])->all();
    }

    public function chooseGuardian(int $key, int $guardianId): void
    {
        Gate::authorize(Permissions::GUARDIANS_VIEW);
        abort_unless(array_key_exists($key, $this->guardians), 404);
        $guardian = Guardian::query()->select(['id', 'first_name', 'middle_name', 'last_name', 'relationship', 'phone', 'email'])->findOrFail($guardianId);
        $this->guardians[$key] = ['mode' => 'existing', 'guardian_id' => $guardian->id, 'label' => $guardian->fullName(), 'title' => '', 'first_name' => '', 'middle_name' => '', 'last_name' => '', 'relationship' => $guardian->relationship ?? 'Legal Guardian', 'phone' => '', 'email' => '', 'address' => ''];
        $this->guardianResults[$key] = [];
    }

    public function save(CreateStudent $creator): void
    {
        Gate::authorize(Permissions::STUDENTS_CREATE);
        foreach ($this->guardians as $row) { Gate::authorize(($row['mode'] ?? 'new') === 'new' ? Permissions::GUARDIANS_CREATE : Permissions::GUARDIANS_VIEW); }

        $rules = [
            'firstName' => ['required', 'string', 'max:255'], 'middleName' => ['nullable', 'string', 'max:255'], 'lastName' => ['required', 'string', 'max:255'],
            'gender' => ['required', Rule::enum(Gender::class)], 'dateOfBirth' => ['nullable', 'date', 'before_or_equal:today'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'], 'status' => ['required', Rule::enum(StudentStatus::class)],
            'academicYearId' => ['required', 'integer', Rule::exists(AcademicYear::class, 'id')], 'classLevelId' => ['required', 'integer', Rule::exists(ClassLevel::class, 'id')],
            'enrollmentDate' => ['required', 'date', 'before_or_equal:today'], 'enrollmentStatus' => ['required', Rule::enum(EnrollmentStatus::class)],
            'guardians' => ['required', 'array', 'min:1', 'max:5'], 'primaryGuardianKey' => ['required', Rule::in(array_keys($this->guardians))],
        ];
        foreach ($this->guardians as $key => $row) {
            $rules["guardians.$key.mode"] = ['required', Rule::in(['new', 'existing'])];
            if (($row['mode'] ?? 'new') === 'existing') { $rules["guardians.$key.guardian_id"] = ['required', 'integer', 'distinct', Rule::exists(Guardian::class, 'id')]; $rules["guardians.$key.relationship"] = ['required', 'string', 'max:80']; continue; }
            foreach (['first_name', 'last_name', 'relationship', 'phone'] as $field) { $rules["guardians.$key.$field"] = ['required', 'string', 'max:255']; }
            foreach (['title', 'middle_name'] as $field) { $rules["guardians.$key.$field"] = ['nullable', 'string', 'max:255']; }
            $rules["guardians.$key.email"] = ['nullable', 'email', 'max:255']; $rules["guardians.$key.address"] = ['nullable', 'string', 'max:2000'];
        }
        $validated = $this->validate($rules);
        $storedPhoto = $this->photo?->store(path: 'students', options: 'public');

        try {
            $student = $creator->handle(
                ['first_name' => trim($validated['firstName']), 'middle_name' => filled($validated['middleName']) ? trim($validated['middleName']) : null, 'last_name' => trim($validated['lastName']), 'gender' => $validated['gender'], 'date_of_birth' => $validated['dateOfBirth'] ?: null, 'photo' => $storedPhoto, 'status' => $validated['status']],
                ['academic_year_id' => $validated['academicYearId'], 'class_level_id' => $validated['classLevelId'], 'enrollment_date' => $validated['enrollmentDate'], 'status' => $validated['enrollmentStatus']],
                collect($validated['guardians'])->map(fn (array $row, int $key): array => ['guardian_id' => ($row['mode'] ?? 'new') === 'existing' ? (int) $row['guardian_id'] : null, 'data' => ($row['mode'] ?? 'new') === 'new' ? collect($row)->only(['title', 'first_name', 'middle_name', 'last_name', 'relationship', 'phone', 'email', 'address'])->map(fn ($value) => is_string($value) ? (filled($value) ? trim($value) : null) : $value)->all() : [], 'relationship' => trim($row['relationship']), 'is_primary' => $key === $validated['primaryGuardianKey']])->values()->all(),
            );
        } catch (UniqueConstraintViolationException) {
            if ($storedPhoto) { Storage::disk('public')->delete($storedPhoto); }
            $this->addError('admissionNumber', 'That admission number is already in use.');

            return;
        } catch (Throwable $exception) {
            if ($storedPhoto) { Storage::disk('public')->delete($storedPhoto); }
            throw $exception;
        }

        session()->flash('student-created', 'Admission Number: '.$student->admission_number);
        Flux::toast(variant: 'success', text: 'Student created. Admission Number: '.$student->admission_number);
        $this->redirectRoute('students.show', $student, navigate: true);
    }

    /** @return array<string, mixed> */
    private function emptyGuardian(): array
    {
        return ['mode' => 'new', 'guardian_id' => null, 'label' => '', 'title' => '', 'first_name' => '', 'middle_name' => '', 'last_name' => '', 'relationship' => '', 'phone' => '', 'email' => '', 'address' => ''];
    }

    /** @return Collection<int, AcademicYear> */
    #[Computed]
    public function academicYears(): Collection
    {
        return AcademicYear::query()->select(['id', 'name'])->orderByDesc('name')->get();
    }

    /** @return Collection<int, ClassLevel> */
    #[Computed]
    public function classLevels(): Collection
    {
        return ClassLevel::query()->select(['id', 'name'])->where('is_active', true)->orderBy('level_order')->get();
    }
};
?>

<form wire:submit="save" class="grid gap-5">
    <div class="flex items-center justify-between"><div><flux:heading size="xl">Add Student</flux:heading><flux:text class="mt-1">Create the personal record, initial placement, and guardian contacts together.</flux:text></div><flux:button :href="route('students.index')" wire:navigate variant="ghost">Cancel</flux:button></div>
    <x-app.panel title="Personal information"><div class="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-3"><div><flux:input :value="$admissionNumberPreview" label="Admission number" description="Generated automatically when the student is saved." disabled />@error('admissionNumber')<p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror @if($admissionNumberPreview === 'School initials not configured')<div class="mt-2">@canany([Permissions::SETTINGS_VIEW, Permissions::SETTINGS_UPDATE])<flux:button size="sm" variant="ghost" :href="route('settings.system')" wire:navigate>Configure in System Settings</flux:button>@endcanany</div>@endif</div><flux:input wire:model="firstName" label="First name" required autofocus /><flux:input wire:model="middleName" label="Middle name" /><flux:input wire:model="lastName" label="Last name" required /><flux:select wire:model="gender" label="Gender" required><option value="">Select gender</option>@foreach(Gender::cases() as $item)<option value="{{ $item->value }}">{{ $item->label() }}</option>@endforeach</flux:select><x-app.student-date-of-birth class="xl:col-span-2" model="dateOfBirth" :value="$dateOfBirth" /><flux:select wire:model="status" label="Status">@foreach(StudentStatus::cases() as $item)<option value="{{ $item->value }}">{{ $item->label() }}</option>@endforeach</flux:select><flux:input wire:model="photo" type="file" label="Photo" accept="image/jpeg,image/png,image/webp" /></div></x-app.panel>
    <x-app.panel title="Initial placement"><div class="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-4"><flux:select wire:model="academicYearId" label="Academic year" required><option value="">Select year</option>@foreach($this->academicYears as $year)<option value="{{ $year->id }}">{{ $year->name }}</option>@endforeach</flux:select><flux:select wire:model="classLevelId" label="Class level" required><option value="">Select class</option>@foreach($this->classLevels as $level)<option value="{{ $level->id }}">{{ $level->name }}</option>@endforeach</flux:select><flux:input wire:model="enrollmentDate" type="date" label="Enrollment date" required /><flux:select wire:model="enrollmentStatus" label="Enrollment status">@foreach(EnrollmentStatus::cases() as $item)<option value="{{ $item->value }}">{{ $item->label() }}</option>@endforeach</flux:select></div></x-app.panel>
    <x-app.panel title="Guardians"><div class="grid gap-4 p-4">@error('guardians')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror @foreach($guardians as $key => $guardian)<section wire:key="guardian-{{ $key }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700"><div class="mb-4 flex items-center justify-between"><label class="flex items-center gap-2 text-sm font-semibold"><input type="radio" wire:model="primaryGuardianKey" value="{{ $key }}"> Primary guardian</label>@if(count($guardians) > 1)<flux:button type="button" size="sm" variant="ghost" wire:click="removeGuardian({{ $key }})" icon="trash">Remove</flux:button>@endif</div>@if($guardian['mode'] === 'existing')<div class="flex items-center justify-between rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800"><div><p class="font-semibold">{{ $guardian['label'] }}</p><p class="text-xs text-zinc-500">Existing guardian record</p></div><flux:button type="button" size="sm" variant="ghost" wire:click="useNewGuardian({{ $key }})">Use new</flux:button></div>@else<div class="mb-4 grid gap-2 sm:grid-cols-[1fr_auto]"><flux:input wire:model="guardianSearch.{{ $key }}" placeholder="Find guardian by name, phone, or email" /><flux:button type="button" wire:click="searchGuardians({{ $key }})">Search existing</flux:button></div>@error("guardianSearch.$key")<p class="mb-3 text-sm text-red-600">{{ $message }}</p>@enderror @if(filled($guardianResults[$key] ?? []))<div class="mb-4 grid gap-2 rounded-lg border p-2">@foreach($guardianResults[$key] as $result)<button type="button" wire:click="chooseGuardian({{ $key }}, {{ $result['id'] }})" class="rounded-md p-2 text-left hover:bg-zinc-100 dark:hover:bg-zinc-800"><span class="font-medium">{{ $result['label'] }}</span><span class="ml-2 text-xs text-zinc-500">{{ $result['detail'] }}</span></button>@endforeach</div>@endif<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"><flux:input wire:model="guardians.{{ $key }}.title" label="Title" /><flux:input wire:model="guardians.{{ $key }}.first_name" label="First name" required /><flux:input wire:model="guardians.{{ $key }}.middle_name" label="Middle name" /><flux:input wire:model="guardians.{{ $key }}.last_name" label="Last name" required /><flux:input wire:model="guardians.{{ $key }}.relationship" label="Relationship" required /><flux:input wire:model="guardians.{{ $key }}.phone" label="Phone" required /><flux:input wire:model="guardians.{{ $key }}.email" type="email" label="Email" /><flux:input wire:model="guardians.{{ $key }}.address" label="Address" /></div>@endif</section>@endforeach<flux:button type="button" class="justify-self-start" variant="ghost" icon="plus" wire:click="addGuardian" :disabled="count($guardians) >= 5">Add another guardian</flux:button></div></x-app.panel>
    <div class="flex justify-end"><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save,photo">Create Student</flux:button></div>
</form>
