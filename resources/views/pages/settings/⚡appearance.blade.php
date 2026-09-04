<?php

use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Appearance settings')] class extends Component {
    public string $theme = 'system';

    public function mount(): void
    {
        $this->theme = auth()->user()->theme ?? 'system';
    }

    public function updatedTheme(): void
    {
        $validated = $this->validateOnly('theme', [
            'theme' => ['required', Rule::in(['light', 'dark', 'system'])],
        ]);

        auth()->user()->update(['theme' => $validated['theme']]);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <div class="grid gap-3">
            <flux:radio.group
                variant="segmented"
                wire:model.live="theme"
                x-on:change="$flux.appearance = $event.target.value"
            >
                <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
                <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
                <flux:radio value="system" icon="computer-desktop">{{ __('Use device setting') }}</flux:radio>
            </flux:radio.group>

            <flux:error name="theme" />
            <flux:text>{{ __('This preference applies only to your account.') }}</flux:text>
        </div>
    </x-pages::settings.layout>
</section>
