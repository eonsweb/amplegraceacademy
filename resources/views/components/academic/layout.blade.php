@props(['heading', 'subheading'])

<section class="w-full">
    <div class="relative mb-6 w-full">
        <flux:heading size="xl" level="1">{{ __('Academic Setup') }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ __('Configure the academic structure used across the school') }}</flux:subheading>
        <flux:separator variant="subtle" />
    </div>

    <x-secondary-navigation-layout :heading="$heading" :subheading="$subheading" content-width="max-w-none">
        <x-slot:navigation>
            <flux:navlist aria-label="Academic Setup">
                @can(\App\Support\Authorization\Permissions::CLASSES_VIEW)
                    <flux:navlist.item :href="route('academic.years.index')" :current="request()->routeIs('academic.years.*')" wire:navigate>{{ __('Academic Years') }}</flux:navlist.item>
                    <flux:navlist.item :href="route('academic.terms.index')" :current="request()->routeIs('academic.terms.*')" wire:navigate>{{ __('Terms') }}</flux:navlist.item>
                    <flux:navlist.item :href="route('academic.class-levels.index')" :current="request()->routeIs('academic.class-levels.*')" wire:navigate>{{ __('Class Levels') }}</flux:navlist.item>
                @endcan
                @can(\App\Support\Authorization\Permissions::SUBJECTS_VIEW)
                    <flux:navlist.item :href="route('academic.subjects.index')" :current="request()->routeIs('academic.subjects.*')" wire:navigate>{{ __('Subjects') }}</flux:navlist.item>
                @endcan
                @if (auth()->user()->canAny([\App\Support\Authorization\Permissions::CLASSES_VIEW, \App\Support\Authorization\Permissions::SUBJECTS_VIEW]))
                    <flux:navlist.item :href="route('academic.class-subjects.index')" :current="request()->routeIs('academic.class-subjects.*')" wire:navigate>{{ __('Class Subjects') }}</flux:navlist.item>
                @endif
            </flux:navlist>
        </x-slot:navigation>

        {{ $slot }}
    </x-secondary-navigation-layout>
</section>
