<?php

use App\EmploymentType;
use App\Events\StaffManagementChanged;
use App\Gender;
use App\Models\Staff;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

new #[Title('Edit Staff')] class extends Component {
    use WithFileUploads;

    #[Locked] public int $staffId;
    #[Locked] public string $staffNumber = '';
    public string $firstName = '';
    public string $lastName = '';
    public string $gender = '';
    public string $dateOfBirth = '';
    public mixed $photo = null;
    public string $roleTitle = '';
    public string $department = '';
    public string $employmentType = '';
    public string $employmentDate = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';

    public function mount(Staff $staff): void
    {
        Gate::authorize('update', $staff);
        $this->staffId = $staff->id;
        $this->staffNumber = $staff->staff_number;
        $this->firstName = $staff->first_name;
        $this->lastName = $staff->last_name;
        $this->gender = $staff->gender?->value ?? '';
        $this->dateOfBirth = $staff->date_of_birth?->toDateString() ?? '';
        $this->roleTitle = $staff->role_title;
        $this->department = $staff->department ?? '';
        $this->employmentType = $staff->employment_type?->value ?? '';
        $this->employmentDate = $staff->employment_date?->toDateString() ?? '';
        $this->phone = $staff->phone ?? '';
        $this->email = $staff->email ?? '';
        $this->address = $staff->address ?? '';
    }

    public function save(): void
    {
        $staff = Staff::query()->findOrFail($this->staffId);
        Gate::authorize('update', $staff);
        $validated = $this->validate([
            'firstName' => ['required', 'string', 'max:255'], 'lastName' => ['required', 'string', 'max:255'],
            'gender' => ['nullable', Rule::enum(Gender::class)], 'dateOfBirth' => ['nullable', 'date', 'before_or_equal:today'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'], 'roleTitle' => ['required', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'], 'employmentType' => ['nullable', Rule::enum(EmploymentType::class)],
            'employmentDate' => ['nullable', 'date', 'before_or_equal:today'], 'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string', 'max:2000'],
        ]);
        $phone = filled($validated['phone']) ? trim($validated['phone']) : null;
        $email = filled($validated['email']) ? str($validated['email'])->trim()->lower()->toString() : null;
        $duplicateExists = $phone !== null || $email !== null
            ? Staff::query()->whereKeyNot($staff->id)
                ->where('first_name', trim($validated['firstName']))
                ->where('last_name', trim($validated['lastName']))
                ->where(function (Builder $query) use ($phone, $email): void {
                    if ($phone !== null) { $query->where('phone', $phone); }
                    if ($email !== null) { $phone === null ? $query->where('email', $email) : $query->orWhere('email', $email); }
                })->exists()
            : false;

        if ($duplicateExists) {
            throw ValidationException::withMessages([$phone !== null ? 'phone' : 'email' => 'Another staff member already uses this phone number or email address.']);
        }

        $oldPhoto = $staff->photo;
        $storedPhoto = $this->photo?->store(path: 'staff', options: 'public');
        $data = [
            'first_name' => trim($validated['firstName']), 'last_name' => trim($validated['lastName']),
            'gender' => $validated['gender'] ?: null, 'date_of_birth' => $validated['dateOfBirth'] ?: null,
            'role_title' => trim($validated['roleTitle']), 'department' => filled($validated['department']) ? trim($validated['department']) : null,
            'employment_type' => $validated['employmentType'] ?: null, 'employment_date' => $validated['employmentDate'] ?: null,
            'phone' => $phone, 'email' => $email, 'address' => filled($validated['address']) ? trim($validated['address']) : null,
            ...($storedPhoto ? ['photo' => $storedPhoto] : []),
        ];
        $changedFields = array_keys(array_filter($data, fn (mixed $value, string $key): bool => $staff->getRawOriginal($key) !== $value, ARRAY_FILTER_USE_BOTH));

        try {
            $staff->update($data);
        } catch (Throwable $exception) {
            if ($storedPhoto) { Storage::disk('public')->delete($storedPhoto); }
            throw $exception;
        }

        if ($storedPhoto && $oldPhoto) { Storage::disk('public')->delete($oldPhoto); }
        StaffManagementChanged::dispatch('staff.updated', (int) auth()->id(), $staff->id, ['fields' => $changedFields]);
        Flux::toast(variant: 'success', text: 'Staff profile updated.');
        $this->redirectRoute('staff.show', $staff, navigate: true);
    }
};
?>

<form wire:submit="save" class="grid gap-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><flux:heading size="xl">Edit Staff</flux:heading><flux:text class="mt-1">Staff number {{ $staffNumber }} is permanent. Employment status and application access are managed separately.</flux:text></div><flux:button :href="route('staff.show', $staffId)" wire:navigate variant="ghost">Cancel</flux:button></div>
    <x-app.panel title="Personal information"><div class="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-3"><flux:input :value="$staffNumber" label="Staff number" disabled /><flux:input wire:model="firstName" label="First name" required autofocus /><flux:input wire:model="lastName" label="Last name" required /><flux:select wire:model="gender" label="Gender"><option value="">Not specified</option>@foreach(Gender::cases() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach</flux:select><flux:input wire:model="dateOfBirth" type="date" label="Date of birth" max="{{ now()->toDateString() }}" /><flux:input wire:model="photo" type="file" label="Replace photo" accept="image/jpeg,image/png,image/webp" /></div></x-app.panel>
    <x-app.panel title="Employment information"><div class="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-3"><flux:input wire:model="roleTitle" label="Position / role title" required /><flux:input wire:model="department" label="Department" /><flux:select wire:model="employmentType" label="Employment type"><option value="">Not specified</option>@foreach(EmploymentType::cases() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach</flux:select><flux:input wire:model="employmentDate" type="date" label="Employment date" max="{{ now()->toDateString() }}" /></div></x-app.panel>
    <x-app.panel title="Contact information"><div class="grid gap-4 p-4 md:grid-cols-2"><flux:input wire:model="phone" type="tel" label="Phone number" /><flux:input wire:model="email" type="email" label="Email address" /><flux:textarea class="md:col-span-2" wire:model="address" label="Address" rows="3" /></div></x-app.panel>
    <div class="flex justify-end"><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save,photo">Save Changes</flux:button></div>
</form>
