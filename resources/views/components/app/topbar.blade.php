<header class="sticky top-0 z-30 flex min-h-17 items-center gap-2 border-b border-zinc-200 bg-white px-3 sm:gap-4 sm:px-6 lg:px-7">
    <button
        type="button"
        x-ref="sidebarToggle"
        class="grid size-10 shrink-0 place-items-center rounded-lg text-zinc-600 hover:bg-zinc-100 hover:text-brand-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700 lg:hidden"
        aria-label="Open navigation"
        aria-controls="app-sidebar"
        x-bind:aria-expanded="sidebarOpen"
        x-on:click="sidebarOpen = true; $nextTick(() => $refs.sidebarClose?.focus())"
    >
        <flux:icon name="bars-3" class="size-5" />
    </button>

    <div class="relative min-w-0 max-w-sm flex-1 lg:max-w-md">
        <label for="dashboard-search" class="sr-only">Search anything</label>
        <flux:icon name="magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 z-10 size-4 -translate-y-1/2 text-zinc-400" aria-hidden="true" />
        <input
            id="dashboard-search"
            type="search"
            placeholder="Search anything..."
            autocomplete="off"
            class="h-10 w-full rounded-lg border border-zinc-200 bg-white pl-9 pr-3 text-sm text-zinc-800 shadow-sm outline-none placeholder:text-zinc-400 focus:border-brand-600 focus:ring-2 focus:ring-brand-600/20"
        >
    </div>

    <div class="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
        <button type="button" class="relative grid size-10 place-items-center rounded-lg text-zinc-700 hover:bg-zinc-100 hover:text-brand-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700" aria-label="Notifications, 5 unread">
            <flux:icon name="bell" class="size-5" />
            <span class="absolute right-0.5 top-0.5 grid min-w-4 place-items-center rounded-full bg-brand-700 px-1 text-[9px] font-bold leading-4 text-white" aria-hidden="true">5</span>
        </button>
        <button type="button" class="relative grid size-10 place-items-center rounded-lg text-zinc-700 hover:bg-zinc-100 hover:text-brand-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700" aria-label="Messages, 3 unread">
            <flux:icon name="envelope" class="size-5" />
            <span class="absolute right-0.5 top-0.5 grid min-w-4 place-items-center rounded-full bg-brand-700 px-1 text-[9px] font-bold leading-4 text-white" aria-hidden="true">3</span>
        </button>

        <span class="mx-1 hidden h-8 w-px bg-zinc-200 sm:block" aria-hidden="true"></span>

        <div class="hidden text-right sm:block">
            <p class="max-w-32 truncate text-sm font-semibold text-zinc-950">{{ auth()->user()->name }}</p>
            <p class="text-xs text-zinc-500">Super Admin</p>
        </div>

        <flux:dropdown position="bottom" align="end">
            <flux:profile
                :initials="auth()->user()->initials()"
                :name="null"
                circle
                aria-label="Open user menu"
                class="focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700"
            />

            <flux:menu>
                <div class="flex items-center gap-2 px-2 py-2 text-start text-sm">
                    <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" circle />
                    <div class="min-w-0">
                        <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                        <flux:text class="truncate">Super Admin</flux:text>
                    </div>
                </div>
                <flux:menu.separator />
                <flux:menu.item :href="route('profile.edit')" icon="user-circle" wire:navigate>
                    {{ __('Profile') }}
                </flux:menu.item>
                <flux:menu.item :href="route('profile.edit')" icon="cog-6-tooth" wire:navigate>
                    {{ __('Settings') }}
                </flux:menu.item>
                <flux:menu.separator />
                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer" data-test="logout-button">
                        {{ __('Log out') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </div>
</header>
