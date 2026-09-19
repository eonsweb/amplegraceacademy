<?php

use App\Concerns\FiltersFinancePeriod;
use App\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\PaymentMethod;
use App\Support\Finance\FinanceSummary;
use App\Support\Settings\SystemSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

return new #[Title('Expense History')] class extends Component {
    use FiltersFinancePeriod, WithPagination;

    /** @var array<string, string>|null */
    protected ?array $resolvedFilters = null;

    #[Url]
    public string $search = '';
    #[Url]
    public string $categoryId = '';
    #[Url]
    public string $method = '';
    #[Url]
    public string $status = '';

    public function boot(): void
    {
        Gate::authorize('viewAny', Expense::class);
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $this->filters();

        return $this->getProvidedView();
    }

    /** @return array<string, string> */
    protected function filters(): array
    {
        if ($this->resolvedFilters !== null) {
            return $this->resolvedFilters;
        }

        $period = $this->periodFilters();
        $validator = Validator::make([
            'categoryId' => $this->categoryId === '' ? null : $this->categoryId,
            'method' => $this->method === '' ? null : $this->method,
            'status' => $this->status === '' ? null : $this->status,
        ], [
            'categoryId' => ['bail', 'nullable', 'integer', Rule::exists('expense_categories', 'id')],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'status' => ['nullable', Rule::enum(ExpenseStatus::class)],
        ], [
            'categoryId.integer' => 'Select a valid expense category.',
            'categoryId.exists' => 'Select a valid expense category.',
            'method.enum' => 'Select a valid payment method.',
            'status.enum' => 'Select a valid expense status.',
        ]);
        $this->resetValidation(['categoryId', 'method', 'status']);
        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return $this->resolvedFilters = ['invalid' => '1'];
        }

        return $this->resolvedFilters = [...$period, 'search' => $this->search, 'expense_category_id' => $this->categoryId,
            'payment_method' => $this->method, 'status' => $this->status];
    }

    /** @return LengthAwarePaginator<int, Expense> */
    #[Computed]
    public function expenses(): LengthAwarePaginator
    {
        /** Historical display snapshots make relation queries unnecessary on the list. */
        return FinanceSummary::expenses($this->filters())
            ->select(['id', 'expense_date', 'description', 'payee', 'category_name', 'academic_year_name',
                'term_name', 'payment_method', 'amount', 'recorded_by_name', 'status'])
            ->orderByDesc('expense_date')->orderByDesc('id')->paginate(app(SystemSettings::class)->recordsPerPage());
    }

    #[Computed]
    public function recordedTotal(): string
    {
        return (string) FinanceSummary::expenses($this->filters())->where('status', ExpenseStatus::Recorded->value)->sum('amount');
    }

    /** @return Collection<int, ExpenseCategory> */
    #[Computed]
    public function categories(): Collection
    {
        return ExpenseCategory::query()->orderBy('name')->get(['id', 'name']);
    }
};
?>
<x-finance.layout heading="Expense History" subheading="Recorded expenses count toward totals. Draft and voided expenses remain available for review.">
    <x-finance.period-filters />
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <flux:input label="Search description, payee or reference" wire:model.live.debounce.400ms="search" />
        <flux:select label="Category" wire:model.live="categoryId">
            <option value="">All categories</option>
            @foreach ($this->categories as $category)<option wire:key="category-filter-{{ $category->id }}" value="{{ $category->id }}">{{ $category->name }}</option>@endforeach
        </flux:select>
        <flux:select label="Payment method" wire:model.live="method">
            <option value="">All methods</option>
            @foreach (PaymentMethod::cases() as $paymentMethod)<option wire:key="method-filter-{{ $paymentMethod->value }}" value="{{ $paymentMethod->value }}">{{ $paymentMethod->label() }}</option>@endforeach
        </flux:select>
        <flux:select label="Status" wire:model.live="status">
            <option value="">All statuses</option>
            @foreach (ExpenseStatus::cases() as $expenseStatus)<option wire:key="status-filter-{{ $expenseStatus->value }}" value="{{ $expenseStatus->value }}">{{ $expenseStatus->label() }}</option>@endforeach
        </flux:select>
    </div>
    <x-app.panel title="Recorded total for selected filters">
        <p class="p-5 text-2xl font-semibold tabular-nums">{{ app(SystemSettings::class)->formatMoney($this->recordedTotal) }}</p>
    </x-app.panel>
    <x-app.panel title="Expenses">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr>
                    <th scope="col" class="p-4">Date / Expense</th>
                    <th scope="col" class="hidden p-4 md:table-cell">Category / Payee</th>
                    <th scope="col" class="hidden p-4 xl:table-cell">Period / Recorder</th>
                    <th scope="col" class="hidden p-4 lg:table-cell">Method</th>
                    <th scope="col" class="p-4 text-right">Amount</th>
                    <th scope="col" class="p-4">Status</th>
                </tr></thead>
                <tbody>
                    @forelse ($this->expenses as $expense)
                        <tr wire:key="expense-{{ $expense->id }}" class="border-t border-zinc-200 dark:border-zinc-700">
                            <td class="max-w-72 p-4">
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ app(SystemSettings::class)->formatDate($expense->expense_date) }}</p>
                                <a class="font-medium underline underline-offset-4" href="{{ route('expenses.show', $expense) }}" wire:navigate>{{ $expense->description }}</a>
                                <p class="text-xs md:hidden">{{ $expense->category_name }}</p>
                            </td>
                            <td class="hidden p-4 md:table-cell">{{ $expense->category_name }}<p class="text-xs">{{ $expense->payee }}</p></td>
                            <td class="hidden p-4 xl:table-cell">{{ $expense->academic_year_name ?? 'Outside academic periods' }} {{ $expense->term_name }}<p class="text-xs">{{ $expense->recorded_by_name }}</p></td>
                            <td class="hidden p-4 lg:table-cell">{{ $expense->payment_method?->label() ?? 'Not specified' }}</td>
                            <td class="whitespace-nowrap p-4 text-right tabular-nums">{{ app(SystemSettings::class)->formatMoney($expense->amount) }}</td>
                            <td class="p-4"><flux:badge size="sm" :color="match ($expense->status) { ExpenseStatus::Recorded => 'green', ExpenseStatus::Voided => 'red', default => 'zinc' }">{{ $expense->status->label() }}</flux:badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-8 text-center">No expenses match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $this->expenses->links() }}</div>
    </x-app.panel>
</x-finance.layout>
