<?php

use App\Models\Guardian;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Add Guardian')] class extends Component {
    public string $title = '';
    public string $firstName = '';
    public string $middleName = '';
    public string $lastName = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';

    public function mount(): void { Gate::authorize('create', Guardian::class); }

    public function save(): void
    {
        Gate::authorize('create', Guardian::class);
        $this->phone = trim($this->phone);
        $this->email = trim($this->email);
        $validated = $this->validate($this->rules());
        $phone = trim($validated['phone']);
        $email = filled($validated['email']) ? str($validated['email'])->trim()->lower()->toString() : null;
        $duplicate = Guardian::query()->select(['id', 'first_name', 'middle_name', 'last_name', 'phone', 'email'])
            ->where(function (Builder $query) use ($phone, $email): void { $query->where('phone', $phone)->when($email !== null, fn (Builder $query): Builder => $query->orWhere('email', $email)); })->first();

        if ($duplicate !== null) {
            throw ValidationException::withMessages(['phone' => 'A guardian with this phone or email may already exist: '.$duplicate->fullName().'. Open the existing record instead.']);
        }

        $guardian = Guardian::query()->create(['title' => filled($validated['title']) ? trim($validated['title']) : null, 'first_name' => trim($validated['firstName']), 'middle_name' => filled($validated['middleName']) ? trim($validated['middleName']) : null, 'last_name' => trim($validated['lastName']), 'relationship' => null, 'phone' => $phone, 'email' => $email, 'address' => filled($validated['address']) ? trim($validated['address']) : null]);
        Flux::toast(variant: 'success', text: 'Guardian created.');
        $this->redirectRoute('guardians.show', $guardian, navigate: true);
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        return ['title' => ['nullable', 'string', 'max:30'], 'firstName' => ['required', 'string', 'max:255'], 'middleName' => ['nullable', 'string', 'max:255'], 'lastName' => ['required', 'string', 'max:255'], 'phone' => ['required', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string', 'max:2000']];
    }
};
?>

<form wire:submit="save" class="grid gap-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><flux:heading size="xl">Add Guardian</flux:heading><flux:text class="mt-1">Create a contact record. Students can be linked after saving.</flux:text></div><flux:button :href="route('guardians.index')" wire:navigate variant="ghost">Cancel</flux:button></div>
    <x-app.panel title="Guardian information"><div class="grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-3"><flux:input wire:model="title" label="Title" maxlength="30" /><flux:input wire:model="firstName" label="First name" required autofocus /><flux:input wire:model="middleName" label="Middle name" /><flux:input wire:model="lastName" label="Last name" required /><flux:input wire:model="phone" type="tel" label="Phone number" required /><flux:input wire:model="email" type="email" label="Email address" /><flux:textarea class="md:col-span-2 xl:col-span-3" wire:model="address" label="Address" rows="3" /></div></x-app.panel>
    <div class="flex justify-end"><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Save Guardian</flux:button></div>
</form>
