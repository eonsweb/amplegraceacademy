@props(['heading', 'subheading' => ''])
@php
    use App\Support\Authorization\Permissions;
    $links = [
        ['expenses.index', Permissions::EXPENSES_VIEW, 'Expense History'],
        ['expenses.create', Permissions::EXPENSES_CREATE, 'Record Expense'],
        ['expenses.categories', Permissions::EXPENSE_CATEGORIES_MANAGE, 'Categories'],
        ['finance.overview', Permissions::FINANCIAL_REPORTS_VIEW, 'Financial Overview'],
    ];
@endphp
<section class="grid w-full min-w-0 gap-5">
    <div>
        <flux:heading size="xl" level="1">{{ $heading }}</flux:heading>
        @if ($subheading)<flux:text>{{ $subheading }}</flux:text>@endif
    </div>
    <nav class="flex flex-wrap gap-2 print:hidden" aria-label="Expenses and finance">
        @foreach ($links as [$routeName, $permission, $label])
            @can($permission)
                <flux:button wire:key="finance-nav-{{ $routeName }}" size="sm" :variant="request()->routeIs($routeName) ? 'primary' : 'ghost'" :href="route($routeName)" wire:navigate>{{ $label }}</flux:button>
            @endcan
        @endforeach
    </nav>
    {{ $slot }}
</section>
