@php
    use App\Support\Authorization\Permissions;

    $user = auth()->user();
    $navigationGroups = [
        'Academic' => [
            ['label' => 'Academic Setup', 'icon' => 'wrench-screwdriver', 'href' => route('academic.index'), 'permission' => [Permissions::CLASSES_VIEW, Permissions::SUBJECTS_VIEW], 'active' => request()->routeIs('academic.*')],
            ['label' => 'Students', 'icon' => 'user-group', 'permission' => Permissions::STUDENTS_VIEW],
            ['label' => 'Teachers', 'icon' => 'academic-cap', 'permission' => Permissions::STAFF_VIEW],
            ['label' => 'Classes', 'icon' => 'book-open', 'permission' => Permissions::CLASSES_VIEW],
            ['label' => 'Subjects', 'icon' => 'building-library', 'permission' => Permissions::SUBJECTS_VIEW],
            ['label' => 'Attendance', 'icon' => 'check-circle', 'permission' => Permissions::ATTENDANCE_VIEW],
            ['label' => 'Examinations', 'icon' => 'clipboard-document-check', 'permission' => Permissions::ASSESSMENTS_VIEW],
            ['label' => 'Grades', 'icon' => 'chart-bar', 'permission' => Permissions::ASSESSMENTS_VIEW],
        ],
        'Finance' => [
            ['label' => 'Fees', 'icon' => 'banknotes', 'permission' => Permissions::FEES_VIEW],
            ['label' => 'Payments', 'icon' => 'wallet', 'permission' => Permissions::PAYMENTS_VIEW],
            ['label' => 'Expenses', 'icon' => 'receipt-percent', 'permission' => Permissions::EXPENSES_VIEW],
        ],
        'System' => [
            ['label' => 'Users', 'icon' => 'users', 'href' => route('users.index'), 'permission' => Permissions::USERS_VIEW, 'active' => request()->routeIs('users.*')],
            ['label' => 'Roles & Permissions', 'icon' => 'lock-closed', 'href' => route('roles.index'), 'permission' => [Permissions::ROLES_VIEW, Permissions::PERMISSIONS_VIEW], 'active' => request()->routeIs('roles.*')],
            ['label' => 'System Settings', 'icon' => 'cog-6-tooth', 'href' => route('settings.system'), 'permission' => [Permissions::SETTINGS_VIEW, Permissions::SETTINGS_UPDATE], 'active' => request()->routeIs('settings.system')],
        ],
    ];

    $navigationGroups = collect($navigationGroups)
        ->map(fn (array $items): array => array_values(array_filter(
            $items,
            fn (array $item): bool => is_array($item['permission'])
                ? $user->canAny($item['permission'])
                : $user->can($item['permission']),
        )))
        ->filter()
        ->all();
@endphp

@inject('systemSettings', 'App\Support\Settings\SystemSettings')

<aside
    id="app-sidebar"
    class="app-sidebar-surface fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col text-white shadow-xl transition-transform duration-200 ease-out lg:translate-x-0 lg:shadow-none motion-reduce:transition-none"
    x-bind:class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
    x-trap.inert.noscroll="sidebarOpen"
    aria-label="Primary navigation"
>
    <div class="flex h-17 min-h-17 shrink-0 items-center gap-3 border-b border-white/10 px-4">
        <img
            src="{{ $systemSettings->dashboardLogoUrl() }}"
            alt="{{ $systemSettings->schoolName() }} crest"
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
        @can(Permissions::DASHBOARD_VIEW)
            <x-app.sidebar-item
                icon="home"
                label="Dashboard"
                :href="route('dashboard')"
                :active="request()->routeIs('dashboard')"
            />
        @endcan

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
                            :active="$item['active'] ?? (isset($item['href']) && request()->url() === $item['href'])"
                        />
                    @endforeach
                </div>
            </section>
        @endforeach
    </nav>

    <footer class="border-t border-white/10 px-6 py-5 text-center text-xs text-white/65">
        &copy; {{ now()->year }} {{ $systemSettings->schoolName() }}
    </footer>
</aside>
