@php
    use App\Support\Authorization\Permissions;

    $user = auth()->user();
    $navigationGroups = [
        'Academic' => [
            ['label' => 'Academic Setup', 'icon' => 'wrench-screwdriver', 'href' => route('academic.index'), 'permission' => [Permissions::CLASSES_VIEW, Permissions::SUBJECTS_VIEW], 'active' => request()->routeIs('academic.*')],
            ['label' => 'Students', 'icon' => 'user-group', 'href' => route('students.index'), 'permission' => Permissions::STUDENTS_VIEW, 'active' => request()->routeIs('students.*')],
            ['label' => 'Guardians / Parents', 'icon' => 'users', 'href' => route('guardians.index'), 'permission' => Permissions::GUARDIANS_VIEW, 'active' => request()->routeIs('guardians.*')],
            ['label' => 'Staff', 'icon' => 'academic-cap', 'href' => route('staff.index'), 'permission' => Permissions::STAFF_VIEW, 'active' => request()->routeIs('staff.*')],
            // ['label' => 'Classes', 'icon' => 'book-open', 'permission' => Permissions::CLASSES_VIEW],
            // ['label' => 'Subjects', 'icon' => 'building-library', 'permission' => Permissions::SUBJECTS_VIEW],
            ['label' => 'Attendance', 'icon' => 'check-circle', 'href' => route('attendance.index'), 'permission' => Permissions::ATTENDANCE_VIEW, 'active' => request()->routeIs('attendance.*')],
            ['label' => 'Assessments', 'icon' => 'clipboard-document-check', 'href' => route('assessments.index'), 'permission' => Permissions::ASSESSMENTS_VIEW, 'active' => request()->routeIs('assessments.*')],
            ['label' => 'Results', 'icon' => 'chart-bar', 'href' => route('results.index'), 'permission' => Permissions::RESULTS_VIEW, 'active' => request()->routeIs('results.*')],
        ],
        'Finance' => [
            ['label' => 'Fees & Payments', 'icon' => 'banknotes', 'href' => route('fees.index'), 'permission' => [Permissions::FEES_VIEW, Permissions::FEES_MANAGE, Permissions::FEE_TYPES_MANAGE, Permissions::INVOICES_VIEW, Permissions::PAYMENTS_RECORD, Permissions::PAYMENTS_VIEW, Permissions::BALANCES_VIEW], 'active' => request()->routeIs('fees.*')],
            ['label' => 'Expenses', 'icon' => 'receipt-percent', 'href' => route('expenses.index'), 'permission' => Permissions::EXPENSES_VIEW, 'active' => request()->routeIs('expenses.index', 'expenses.show', 'expenses.edit')],
            ['label' => 'Record Expense', 'icon' => 'plus', 'href' => route('expenses.create'), 'permission' => Permissions::EXPENSES_CREATE, 'active' => request()->routeIs('expenses.create')],
            ['label' => 'Expense Categories', 'icon' => 'tag', 'href' => route('expenses.categories'), 'permission' => Permissions::EXPENSE_CATEGORIES_MANAGE, 'active' => request()->routeIs('expenses.categories')],
            ['label' => 'Financial Overview', 'icon' => 'chart-bar', 'href' => route('finance.overview'), 'permission' => Permissions::FINANCIAL_REPORTS_VIEW, 'active' => request()->routeIs('finance.*')],
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
