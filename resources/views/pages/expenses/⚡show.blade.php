<?php

use App\Actions\Expenses\SaveExpense;
use App\Actions\Expenses\VoidExpense;
use App\ExpenseStatus;
use App\Models\Expense;
use App\Support\Authorization\Permissions;
use App\Support\Settings\SystemSettings;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

return new #[Title('Expense Details')] class extends Component {
    use WithPagination;

    #[Locked]
    public int $expenseId;
    public bool $showVoid = false;
    public string $reason = '';

    public function mount(Expense $expense): void
    {
        Gate::authorize('view', $expense);
        $this->expenseId = $expense->id;
    }

    public function boot(): void
    {
        Gate::authorize(Permissions::EXPENSES_VIEW);
    }

    #[Computed]
    public function expense(): Expense
    {
        return Expense::query()->with('voidedBy:id,name')->findOrFail($this->expenseId);
    }

    /** @return LengthAwarePaginator<int, stdClass> */
    #[Computed]
    public function audits(): LengthAwarePaginator
    {
        return DB::table('financial_audits')->join('users', 'users.id', '=', 'financial_audits.actor_id')
            ->where('record_type', 'expense')->where('record_id', $this->expenseId)
            ->select('financial_audits.*', 'users.name as actor_name')->orderByDesc('financial_audits.id')->paginate(10);
    }

    public function openVoid(): void
    {
        Gate::authorize('void', $this->expense());
        $this->resetValidation();
        $this->reason = '';
        $this->showVoid = true;
    }

    public function voidExpense(): void
    {
        $this->resetValidation();
        app(VoidExpense::class)->handle(auth()->user(), $this->expenseId, $this->reason);
        $this->showVoid = false;
        unset($this->expense, $this->audits);
        \Flux\Flux::toast(variant: 'success', text: 'Expense voided. Its history has been preserved.');
    }

    public function deleteDraft(): void
    {
        app(SaveExpense::class)->deleteDraft(auth()->user(), $this->expenseId);
        $this->redirectRoute('expenses.index', navigate: true);
    }
};
?>
<x-finance.layout :heading="'Expense #'.$expenseId" subheading="Recorded details and the history of changes are preserved.">
    @php
        $expense = $this->expense;
    @endphp
    <div class="flex flex-wrap items-center gap-3">
        <flux:badge :color="match ($expense->status) { ExpenseStatus::Recorded => 'green', ExpenseStatus::Voided => 'red', default => 'zinc' }">{{ $expense->status->label() }}</flux:badge>
        @can('update', $expense)<flux:button :href="route('expenses.edit', $expense)" wire:navigate>Edit draft</flux:button>@endcan
        @can('void', $expense)<flux:button variant="danger" wire:click="openVoid">Void expense</flux:button>@endcan
        @can('delete', $expense)<flux:button variant="danger" wire:click="deleteDraft" wire:confirm="Delete this draft? Its audit history will be retained." wire:loading.attr="disabled">Delete draft</flux:button>@endcan
    </div>
    <x-app.panel title="Expense details">
        <dl class="grid gap-5 p-5 sm:grid-cols-2 xl:grid-cols-3">
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Amount</dt><dd class="text-2xl font-semibold tabular-nums">{{ app(SystemSettings::class)->formatMoney($expense->amount) }}</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Expense date</dt><dd>{{ app(SystemSettings::class)->formatDate($expense->expense_date) }}</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Category</dt><dd>{{ $expense->category_name }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-sm text-zinc-500 dark:text-zinc-400">Description</dt><dd class="break-words">{{ $expense->description }}</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Payee</dt><dd>{{ $expense->payee ?? 'Not specified' }}</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Academic period</dt><dd>{{ $expense->academic_year_name ?? 'Outside academic periods' }} {{ $expense->term_name }}</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Payment method / Reference</dt><dd>{{ $expense->payment_method?->label() ?? 'Not specified' }}<p>{{ $expense->reference }}</p></dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Entered by</dt><dd>{{ $expense->recorded_by_name }}</dd></div>
            @if ($expense->notes)<div class="sm:col-span-2"><dt class="text-sm text-zinc-500 dark:text-zinc-400">Notes</dt><dd class="whitespace-pre-wrap break-words">{{ $expense->notes }}</dd></div>@endif
        </dl>
    </x-app.panel>
    @if ($expense->status === ExpenseStatus::Voided)
        <flux:callout variant="danger" heading="Voided expense">
            {{ $expense->void_reason }} — {{ $expense->voidedBy?->name }}, {{ app(SystemSettings::class)->formatDate($expense->voided_at) }}.
            This expense is excluded from financial totals.
        </flux:callout>
    @endif
    <x-app.panel title="Audit history">
        <div class="grid gap-4 p-5">
            @forelse ($this->audits as $audit)
                @php
                    $changes = json_decode($audit->changes ?? '{}', true);
                    $before = $changes['before'] ?? [];
                    $after = $changes['after'] ?? [];
                    $labels = ['amount' => 'Amount', 'expense_date' => 'Expense date', 'category_name' => 'Category',
                        'description' => 'Description', 'payee' => 'Payee', 'academic_year_name' => 'Academic year',
                        'term_name' => 'Term', 'payment_method' => 'Payment method', 'reference' => 'Reference',
                        'notes' => 'Notes', 'status' => 'Status', 'void_reason' => 'Void reason'];
                @endphp
                <article wire:key="audit-{{ $audit->id }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <p class="font-medium">{{ match ($audit->action) { 'expense.recorded' => 'Expense recorded', 'expense.voided' => 'Expense voided', default => 'Draft saved' } }} — {{ $audit->actor_name }}</p>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $audit->created_at }}</p>
                    <dl class="mt-3 grid gap-2 text-sm">
                        @foreach ($labels as $field => $label)
                            @if (($before[$field] ?? null) !== ($after[$field] ?? null))
                                <div wire:key="audit-field-{{ $audit->id }}-{{ $field }}" class="break-words">
                                    <dt class="font-medium">{{ $label }}</dt>
                                    <dd>{{ $before[$field] ?? '—' }} → {{ $after[$field] ?? '—' }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>
                </article>
            @empty
                <flux:text>No audit entries are available.</flux:text>
            @endforelse
            {{ $this->audits->links() }}
        </div>
    </x-app.panel>
    <flux:modal wire:model="showVoid" class="max-w-lg">
        <form wire:submit="voidExpense" class="grid gap-4">
            <flux:heading size="lg">Void expense #{{ $expenseId }}</flux:heading>
            <flux:text>The original details will remain in history. This removes the expense from financial totals.</flux:text>
            <flux:textarea label="Reason for voiding" wire:model="reason" required />
            <flux:button variant="danger" type="submit" wire:loading.attr="disabled">Confirm void</flux:button>
        </form>
    </flux:modal>
</x-finance.layout>
