<?php

use App\Models\Guardian;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit Guardian')] class extends Component {
    #[Locked] public int $guardianId;
    public string $title = '';
    public string $firstName = '';
    public string $middleName = '';
    public string $lastName = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';

    public function mount(Guardian $guardian): void
    {
        Gate::authorize('update', $guardian);
        $this->guardianId = $guardian->id;
        $this->title = $guardian->title ?? '';
        $this->firstName = $guardian->first_name;
        $this->middleName = $guardian->middle_name ?? '';
        $this->lastName = $guardian->last_name;
        $this->phone = $guardian->phone;
        $this->email = $guardian->email ?? '';
        $this->address = $guardian->address ?? '';
    }

    public function save(): void
    {
        $guardian = Guardian::query()->findOrFail($this->guardianId);
        Gate::authorize('update', $guardian);
        $this->phone = trim($this->phone);
        $this->email = trim($this->email);
        $validated = $this->validate(['title' => ['nullable', 'string', 'max:30'], 'firstName' => ['required', 'string', 'max:255'], 'middleName' => ['nullable', 'string', 'max:255'], 'lastName' => ['required', 'string', 'max:255'], 'phone' => ['required', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string', 'max:2000']]);
        $phone = trim($validated['phone']);
        $email = filled($validated['email']) ? str($validated['email'])->trim()->lower()->toString() : null;
        $duplicateExists = Guardian::query()->whereKeyNot($guardian->id)->where(function (Builder $query) use ($phone, $email): void { $query->where('phone', $phone)->when($email !== null, fn (Builder $query): Builder => $query->orWhere('email', $email)); })->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages(['phone' => 'Another guardian already uses this phone number or email address.']);
        }

        $guardian->update(['title' => filled($validated['title']) ? trim($validated['title']) : null, 'first_name' => trim($validated['firstName']), 'middle_name' => filled($validated['middleName']) ? trim($validated['middleName']) : null, 'last_name' => trim($validated['lastName']), 'phone' => $phone, 'email' => $email, 'address' => filled($validated['address']) ? trim($validated['address']) : null]);
        Flux::toast(variant: 'success', text: 'Guardian updated.');
        $this->redirectRoute('guardians.show', $guardian, navigate: true);
    }
};
?>

<form wire:submit="save" class="grid gap-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><flux:heading size="xl">Edit Guardian</flux:heading><flux:text class="mt-1">Update guardian contact information.</flux:text></div><flux:button :href="route('guardians.show', $guardianId)" wire:navigate variant="ghost">Cancel</flux:button></div>
    <x-app.panel title="Guardian information"><div class="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-3"><flux:input wire:model="title" label="Title" maxlength="30" /><flux:input wire:model="firstName" label="First name" required autofocus /><flux:input wire:model="middleName" label="Middle name" /><flux:input wire:model="lastName" label="Last name" required /><flux:input wire:model="phone" type="tel" label="Phone number" required /><flux:input wire:model="email" type="email" label="Email address" /><flux:textarea class="md:col-span-2 xl:col-span-3" wire:model="address" label="Address" rows="3" /></div></x-app.panel>
    <div class="flex justify-end"><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Save Changes</flux:button></div>
</form>
