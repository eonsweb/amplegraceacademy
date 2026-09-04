<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Settings') }}">
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
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
