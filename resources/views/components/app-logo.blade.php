@props([
    'sidebar' => false,
])

@if($sidebar)
    @inject('systemSettings', 'App\Support\Settings\SystemSettings')
    <flux:sidebar.brand :name="$systemSettings->schoolName()" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
        </x-slot>
    </flux:sidebar.brand>
@else
    @inject('systemSettings', 'App\Support\Settings\SystemSettings')
    <flux:brand :name="$systemSettings->schoolName()" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
        </x-slot>
    </flux:brand>
@endif
