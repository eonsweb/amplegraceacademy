@php
    $navigationGroups = [
        'Academic' => [
            ['label' => 'Students', 'icon' => 'user-group'],
            ['label' => 'Teachers', 'icon' => 'academic-cap'],
            ['label' => 'Classes', 'icon' => 'book-open'],
            ['label' => 'Subjects', 'icon' => 'building-library'],
            ['label' => 'Timetable', 'icon' => 'calendar-days'],
            ['label' => 'Attendance', 'icon' => 'check-circle'],
            ['label' => 'Examinations', 'icon' => 'clipboard-document-check'],
            ['label' => 'Grades', 'icon' => 'chart-bar'],
        ],
        'Finance' => [
            ['label' => 'Fees', 'icon' => 'banknotes'],
            ['label' => 'Payments', 'icon' => 'wallet'],
            ['label' => 'Expenses', 'icon' => 'receipt-percent'],
            ['label' => 'Invoices', 'icon' => 'document-text'],
        ],
        'Communication' => [
            ['label' => 'Notices', 'icon' => 'bell'],
            ['label' => 'Messages', 'icon' => 'envelope'],
            ['label' => 'Events', 'icon' => 'calendar'],
        ],
        'System' => [
            ['label' => 'Users', 'icon' => 'users'],
            ['label' => 'Roles & Permissions', 'icon' => 'lock-closed'],
            ['label' => 'Settings', 'icon' => 'cog-6-tooth', 'href' => route('profile.edit')],
        ],
    ];
@endphp

<aside
    id="app-sidebar"
    class="app-sidebar-surface fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col text-white shadow-xl transition-transform duration-200 ease-out lg:translate-x-0 lg:shadow-none motion-reduce:transition-none"
    x-bind:class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
    x-trap.inert.noscroll="sidebarOpen"
    aria-label="Primary navigation"
>
    <div class="flex h-17 min-h-17 shrink-0 items-center gap-3 border-b border-white/10 px-4">
        <img
            src="{{ asset('images/branding/ample-grace-logo.png') }}"
            alt="Ample Grace Academy crest"
            width="140"
            height="150"
            class="h-12 w-auto shrink-0 object-contain"
        >
        <div class="min-w-0 leading-tight">
            <p class="whitespace-nowrap font-serif text-sm font-semibold tracking-wide text-white">SHAPING FUTURES</p>
            <p class="mt-0.5 whitespace-nowrap font-serif text-[10px] tracking-wide text-white/70">
                TRANSFORMING <span class="text-base italic text-white">Lives</span>
            </p>
        </div>
        <button
            type="button"
            x-ref="sidebarClose"
            class="ml-auto grid size-10 shrink-0 place-items-center rounded-lg text-white/80 hover:bg-white/10 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white lg:hidden"
            aria-label="Close navigation"
            x-on:click="sidebarOpen = false; $nextTick(() => $refs.sidebarToggle?.focus())"
        >
            <flux:icon name="x-mark" class="size-5" />
        </button>
    </div>

    <nav class="flex-1 overflow-y-auto px-4 py-4" aria-label="School administration">
        <x-app.sidebar-item
            icon="home"
            label="Dashboard"
            :href="route('dashboard')"
            :active="request()->routeIs('dashboard')"
        />

        @foreach ($navigationGroups as $group => $items)
            <section class="mt-5" aria-labelledby="sidebar-{{ str($group)->slug() }}">
                <h2 id="sidebar-{{ str($group)->slug() }}" class="px-2 text-[11px] font-semibold uppercase tracking-wider text-white/55">
                    {{ $group }}
                </h2>
                <div class="mt-1.5 grid gap-0.5">
                    @foreach ($items as $item)
                        <x-app.sidebar-item
                            :icon="$item['icon']"
                            :label="$item['label']"
                            :href="$item['href'] ?? null"
                            :active="isset($item['href']) && request()->url() === $item['href']"
                        />
                    @endforeach
                </div>
            </section>
        @endforeach
    </nav>

    <footer class="border-t border-white/10 px-6 py-5 text-center text-xs text-white/65">
        &copy; 2025 {{ config('app.name', 'Ample Grace Academy') }}
    </footer>
</aside>
