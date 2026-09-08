<?php

use App\Actions\Staff\CreateStaff;
use App\EmploymentType;
use App\Events\StaffManagementChanged;
use App\Gender;
use App\Models\Staff;
use App\StaffStatus;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

new #[Title('Add Staff')] class extends Component {
    use WithFileUploads;

    #[Locked] public string $staffNumberPreview = 'STFXXXXXX';
    public string $firstName = '';
    public string $lastName = '';
    public string $gender = '';
    public string $dateOfBirth = '';
    public mixed $photo = null;
    public string $roleTitle = '';
    public string $department = '';
    public string $employmentType = '';
    public string $employmentDate = '';
    public string $status = 'active';
    public string $phone = '';
    public string $email = '';
    public string $address = '';

    public function mount(): void
    {
        Gate::authorize('create', Staff::class);
        $this->employmentDate = now()->toDateString();
    }

    public function save(CreateStaff $creator): void
    {
        Gate::authorize('create', Staff::class);
        $validated = $this->validate($this->rules());
        $phone = filled($validated['phone']) ? trim($validated['phone']) : null;
        $email = filled($validated['email']) ? str($validated['email'])->trim()->lower()->toString() : null;
        $duplicate = $phone === null && $email === null
            ? null
            : Staff::query()->select(['id', 'staff_number', 'first_name', 'last_name'])
                ->where('first_name', trim($validated['firstName']))
                ->where('last_name', trim($validated['lastName']))
                ->where(function (Builder $query) use ($phone, $email): void {
                    if ($phone !== null) { $query->where('phone', $phone); }
                    if ($email !== null) { $phone === null ? $query->where('email', $email) : $query->orWhere('email', $email); }
                })->first();

        if ($duplicate !== null) {
            throw ValidationException::withMessages([
                $phone !== null ? 'phone' : 'email' => 'A staff member with this name and contact detail may already exist: '.$duplicate->fullName().' ('.$duplicate->staff_number.').',
            ]);
        }

        $storedPhoto = $this->photo?->store(path: 'staff', options: 'public');

        try {
            $staff = $creator->handle($this->staffData($validated, $storedPhoto));
        } catch (UniqueConstraintViolationException) {
            if ($storedPhoto) { Storage::disk('public')->delete($storedPhoto); }
            $this->addError('staffNumber', 'A unique staff number could not be generated. Please try again.');

            return;
        } catch (Throwable $exception) {
            if ($storedPhoto) { Storage::disk('public')->delete($storedPhoto); }
            throw $exception;
        }

        StaffManagementChanged::dispatch('staff.created', (int) auth()->id(), $staff->id);
        session()->flash('staff-created', 'Staff Number: '.$staff->staff_number);
        Flux::toast(variant: 'success', text: 'Staff member created.');
        $this->redirectRoute('staff.show', $staff, navigate: true);
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'firstName' => ['required', 'string', 'max:255'], 'lastName' => ['required', 'string', 'max:255'],
            'gender' => ['nullable', Rule::enum(Gender::class)], 'dateOfBirth' => ['nullable', 'date', 'before_or_equal:today'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'], 'roleTitle' => ['required', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'], 'employmentType' => ['nullable', Rule::enum(EmploymentType::class)],
            'employmentDate' => ['nullable', 'date', 'before_or_equal:today'], 'status' => ['required', Rule::enum(StaffStatus::class)],
            'phone' => ['nullable', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @param array<string, mixed> $validated @return array<string, mixed> */
    private function staffData(array $validated, ?string $photo): array
    {
        return [
            'first_name' => trim($validated['firstName']), 'last_name' => trim($validated['lastName']),
            'gender' => $validated['gender'] ?: null, 'date_of_birth' => $validated['dateOfBirth'] ?: null,
            'photo' => $photo, 'role_title' => trim($validated['roleTitle']),
            'department' => filled($validated['department']) ? trim($validated['department']) : null,
            'employment_type' => $validated['employmentType'] ?: null, 'employment_date' => $validated['employmentDate'] ?: null,
            'status' => $validated['status'], 'phone' => filled($validated['phone']) ? trim($validated['phone']) : null,
            'email' => filled($validated['email']) ? str($validated['email'])->trim()->lower()->toString() : null,
            'address' => filled($validated['address']) ? trim($validated['address']) : null,
        ];
    }
};
?>

<form wire:submit="save" class="grid gap-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><flux:heading size="xl">Add Staff</flux:heading><flux:text class="mt-1">Create an employment record. Application access is optional and managed after saving.</flux:text></div><flux:button :href="route('staff.index')" wire:navigate variant="ghost">Cancel</flux:button></div>
    <x-app.panel title="Personal information"><div class="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-3"><div><flux:input :value="$staffNumberPreview" label="Staff number" description="Generated automatically when saved." disabled />@error('staffNumber')<p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror</div><flux:input wire:model="firstName" label="First name" required autofocus /><flux:input wire:model="lastName" label="Last name" required /><flux:select wire:model="gender" label="Gender"><option value="">Not specified</option>@foreach(Gender::cases() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach</flux:select><flux:input wire:model="dateOfBirth" type="date" label="Date of birth" max="{{ now()->toDateString() }}" /><flux:input wire:model="photo" type="file" label="Photo" accept="image/jpeg,image/png,image/webp" /></div></x-app.panel>
    <x-app.panel title="Employment information"><div class="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-3"><flux:input wire:model="roleTitle" label="Position / role title" required /><flux:input wire:model="department" label="Department" /><flux:select wire:model="employmentType" label="Employment type"><option value="">Not specified</option>@foreach(EmploymentType::cases() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach</flux:select><flux:input wire:model="employmentDate" type="date" label="Employment date" max="{{ now()->toDateString() }}" /><flux:select wire:model="status" label="Employment status" required>@foreach(StaffStatus::cases() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach</flux:select></div></x-app.panel>
    <x-app.panel title="Contact information"><div class="grid gap-4 p-4 md:grid-cols-2"><flux:input wire:model="phone" type="tel" label="Phone number" /><flux:input wire:model="email" type="email" label="Email address" /><flux:textarea class="md:col-span-2" wire:model="address" label="Address" rows="3" /></div></x-app.panel>
    <div class="flex justify-end"><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save,photo"><span wire:loading.remove wire:target="save">Save Staff</span><span wire:loading wire:target="save">Saving...</span></flux:button></div>
</form>
