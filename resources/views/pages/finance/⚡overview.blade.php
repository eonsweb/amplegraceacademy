<?php

use App\Concerns\FiltersFinancePeriod;
use App\Support\Authorization\Permissions;
use App\Support\Finance\FinanceSummary;
use App\Support\Settings\SystemSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

return new #[Title('Financial Overview')] class extends Component {
    use FiltersFinancePeriod, WithPagination;

    public function boot(): void
    {
        Gate::authorize(Permissions::FINANCIAL_REPORTS_VIEW);
    }

    public function updated(string $property): void
    {
        $this->resetPage('categoriesPage');
        $this->resetPage('datesPage');
    }

    public function render(): View
    {
        $this->periodFilters();

        return $this->getProvidedView();
    }

    /** @return array{income: string, expenses: string, net: string} */
    #[Computed]
    public function totals(): array
    {
        return FinanceSummary::totals($this->periodFilters());
    }

    /** @return LengthAwarePaginator<int, stdClass> */
    #[Computed]
    public function categoryTotals(): LengthAwarePaginator
    {
        return FinanceSummary::byCategory($this->periodFilters())->paginate(10, ['*'], 'categoriesPage');
    }

    /** @return LengthAwarePaginator<int, stdClass> */
    #[Computed]
    public function dailyTotals(): LengthAwarePaginator
    {
        return FinanceSummary::byDate($this->periodFilters())->paginate(10, ['*'], 'datesPage');
    }
};
?>
<x-finance.layout heading="Financial Overview" subheading="Payments received, recorded expenses and the resulting net position for the selected period.">
    <x-finance.period-filters />
    <flux:callout>
        Dates use the payment date for income and the expense date for expenditure.
        Academic filters match payments allocated to invoices in that year or term.
        Drafts and voided transactions are excluded. Net position is income less recorded expenses; it is not a bank balance.
    </flux:callout>
    <div class="grid gap-4 md:grid-cols-3">
        @foreach (['income' => 'Payments received', 'expenses' => 'Recorded expenses', 'net' => 'Net position'] as $key => $label)
            <x-app.panel :title="$label" wire:key="finance-total-{{ $key }}">
                <p class="p-5 text-2xl font-semibold tabular-nums">{{ app(SystemSettings::class)->formatMoney($this->totals[$key]) }}</p>
            </x-app.panel>
        @endforeach
    </div>
    <div class="grid gap-5 xl:grid-cols-2">
        <x-app.panel title="Expenses by category">
            <table class="w-full text-left text-sm">
                <thead><tr><th scope="col" class="p-4">Category</th><th scope="col" class="p-4 text-right">Total</th></tr></thead>
                <tbody>
                    @forelse ($this->categoryTotals as $row)
                        <tr wire:key="category-total-{{ $row->expense_category_id }}" class="border-t border-zinc-200 dark:border-zinc-700">
                            <td class="p-4">{{ $row->name }}</td><td class="p-4 text-right tabular-nums">{{ app(SystemSettings::class)->formatMoney($row->total) }}</td>
                        </tr>
                    @empty<tr><td colspan="2" class="p-6 text-center">No recorded expenses for this period.</td></tr>@endforelse
                </tbody>
            </table>
            <div class="p-4">{{ $this->categoryTotals->links() }}</div>
        </x-app.panel>
        <x-app.panel title="Expenses by date">
            <table class="w-full text-left text-sm">
                <thead><tr><th scope="col" class="p-4">Expense date</th><th scope="col" class="p-4 text-right">Total</th></tr></thead>
                <tbody>
                    @forelse ($this->dailyTotals as $row)
                        <tr wire:key="date-total-{{ $row->expense_date }}" class="border-t border-zinc-200 dark:border-zinc-700">
                            <td class="p-4">{{ app(SystemSettings::class)->formatDate($row->expense_date) }}</td><td class="p-4 text-right tabular-nums">{{ app(SystemSettings::class)->formatMoney($row->total) }}</td>
                        </tr>
                    @empty<tr><td colspan="2" class="p-6 text-center">No recorded expenses for this period.</td></tr>@endforelse
                </tbody>
            </table>
            <div class="p-4">{{ $this->dailyTotals->links() }}</div>
        </x-app.panel>
    </div>
</x-finance.layout>
