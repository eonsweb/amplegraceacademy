<?php

use App\Models\SchoolSetting;
use App\Support\Authorization\Permissions;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('System settings')] class extends Component {
    public string $schoolName = '';

    public string $contactEmail = '';

    public string $phone = '';

    public string $address = '';

    public function mount(): void
    {
        abort_unless(Gate::any([Permissions::SETTINGS_VIEW, Permissions::SETTINGS_UPDATE]), 403);

        $settings = SchoolSetting::query()->first();

        $this->schoolName = $settings?->school_name ?? config('app.name');
        $this->contactEmail = $settings?->contact_email ?? '';
        $this->phone = $settings?->phone ?? '';
        $this->address = $settings?->address ?? '';
    }

    public function save(): void
    {
        Gate::authorize(Permissions::SETTINGS_UPDATE);

        $validated = $this->validate([
            'schoolName' => ['required', 'string', 'max:255'],
            'contactEmail' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        SchoolSetting::query()->updateOrCreate([
            'id' => 1,
        ], [
            'school_name' => trim($validated['schoolName']),
            'contact_email' => $validated['contactEmail'] ?: null,
            'phone' => $validated['phone'] ?: null,
            'address' => $validated['address'] ?: null,
        ]);

        Flux::toast(variant: 'success', text: 'System settings updated.');
    }
};
?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('System settings')" :subheading="__('Manage school-wide contact information')">
        @cannot(Permissions::SETTINGS_UPDATE)
            <flux:callout class="mb-5" variant="warning" icon="eye" heading="Read-only access" text="You can review these settings, but you do not have permission to change them." />
        @endcannot

        <form wire:submit="save" class="grid gap-5">
            <flux:input wire:model="schoolName" label="School name" maxlength="255" required :disabled="! auth()->user()->can(Permissions::SETTINGS_UPDATE)" />
            <flux:input wire:model="contactEmail" type="email" label="Contact email" maxlength="255" :disabled="! auth()->user()->can(Permissions::SETTINGS_UPDATE)" />
            <flux:input wire:model="phone" type="tel" label="Phone" maxlength="30" :disabled="! auth()->user()->can(Permissions::SETTINGS_UPDATE)" />
            <flux:textarea wire:model="address" label="Address" rows="4" maxlength="500" :disabled="! auth()->user()->can(Permissions::SETTINGS_UPDATE)" />

            @can(Permissions::SETTINGS_UPDATE)
                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary">Save settings</flux:button>
                </div>
            @endcan
        </form>
    </x-pages::settings.layout>
</section>
