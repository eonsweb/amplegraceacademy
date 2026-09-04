<x-secondary-navigation-layout :heading="$heading ?? ''" :subheading="$subheading ?? ''">
    <x-slot:navigation>
        <flux:navlist aria-label="Settings">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('security.edit')" wire:navigate>{{ __('Security') }}</flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
            @if (auth()->user()->canAny([\App\Support\Authorization\Permissions::SETTINGS_VIEW, \App\Support\Authorization\Permissions::SETTINGS_UPDATE]))
                <flux:navlist.item :href="route('settings.system')" wire:navigate>{{ __('System settings') }}</flux:navlist.item>
            @endif
            @can(\App\Support\Authorization\Permissions::USERS_VIEW)
                <flux:navlist.item :href="route('users.index')" wire:navigate>{{ __('Users') }}</flux:navlist.item>
            @endcan
            @if (auth()->user()->canAny([\App\Support\Authorization\Permissions::ROLES_VIEW, \App\Support\Authorization\Permissions::PERMISSIONS_VIEW]))
                <flux:navlist.item :href="route('roles.index')" wire:navigate>{{ __('Roles & permissions') }}</flux:navlist.item>
            @endif
        </flux:navlist>
    </x-slot:navigation>

    {{ $slot }}
</x-secondary-navigation-layout>
