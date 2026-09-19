<?php

use App\Actions\Expenses\SaveExpense;
use App\Models\AcademicYear;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Term;
use App\PaymentMethod;
use App\Support\Authorization\Permissions;
use App\Support\Settings\SystemSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Record Expense')] class extends Component {
    #[Locked]
    public ?int $expenseId = null;

    #[Locked]
    public string $submissionKey = '';

    #[Locked]
    public ?int $savedId = null;

    /** @var array<string, string> */
    public array $form = [
        'expense_category_id' => '', 'academic_year_id' => '', 'term_id' => '',
        'expense_date' => '', 'amount' => '', 'description' => '', 'payee' => '',
        'payment_method' => '', 'reference' => '', 'notes' => '',
    ];

    public function mount(?Expense $expense = null): void
    {
        if ($expense?->exists) {
            Gate::authorize('update', $expense);
            $this->expenseId = $expense->id;
            $this->submissionKey = $expense->submission_key;
            foreach (array_keys($this->form) as $field) {
                $this->form[$field] = match ($field) {
                    'expense_date' => $expense->expense_date->toDateString(),
                    'payment_method' => $expense->payment_method->value ?? '',
                    default => (string) ($expense->getAttribute($field) ?? ''),
                };
            }
        } else {
            Gate::authorize('create', Expense::class);
            $this->submissionKey = (string) Str::uuid();
            $this->form['expense_date'] = now(app(SystemSettings::class)->timezone())->toDateString();
            $year = AcademicYear::query()->where('is_current', true)->first();
            $this->form['academic_year_id'] = (string) ($year->id ?? '');
            $this->form['term_id'] = (string) (Term::query()->where('academic_year_id', $year?->id)->where('is_current', true)->value('id') ?? '');
        }
    }

    public function boot(): void
    {
        abort_unless(auth()->user()?->canAny([Permissions::EXPENSES_CREATE, Permissions::EXPENSES_UPDATE]), 403);
    }

    public function updatedForm(string $value, string $key): void
    {
        if ($key === 'academic_year_id') {
            $this->form['term_id'] = '';
            unset($this->terms);
        }
    }

    /** @return Collection<int, ExpenseCategory> */
    #[Computed]
    public function categories(): Collection
    {
        return ExpenseCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int, AcademicYear> */
    #[Computed]
    public function years(): Collection
    {
        return AcademicYear::query()->orderByDesc('name')->get(['id', 'name']);
    }

    /** @return Collection<int, Term> */
    #[Computed]
    public function terms(): Collection
    {
        return Term::query()->where('academic_year_id', $this->form['academic_year_id'] ?: 0)->orderBy('term_order')->get(['id', 'name']);
    }

    public function save(bool $record = true): void
    {
        if ($this->savedId !== null) {
            return;
        }
        $this->resetValidation();
        try {
            $expense = app(SaveExpense::class)->handle(auth()->user(), [...$this->form, 'submission_key' => $this->submissionKey], $record, $this->expenseId);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors['form.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $this->savedId = $expense->id;
        session()->flash('expense-saved', $record ? 'Expense recorded.' : 'Draft saved. It is excluded from financial totals until recorded.');
    }
};
?>

<x-finance.layout :heading="$expenseId ? 'Edit Draft Expense' : 'Record Expense'" subheading="Save a draft to edit later, or record the expense to include it in financial totals.">
    @if ($savedId)
        <flux:callout variant="success" heading="{{ session('expense-saved', 'Expense saved.') }}">
            <div class="flex flex-wrap gap-3">
                @can(Permissions::EXPENSES_VIEW)<flux:button :href="route('expenses.show', $savedId)" wire:navigate>View expense</flux:button>@endcan
                @can(Permissions::EXPENSES_CREATE)<flux:button :href="route('expenses.create')" wire:navigate>Record another expense</flux:button>@endcan
            </div>
        </flux:callout>
    @else
        @if ($this->categories->isEmpty())
            <flux:callout heading="An active category is needed">
                Ask an authorized colleague to create or activate an expense category.
                @can(Permissions::EXPENSE_CATEGORIES_MANAGE)<flux:button :href="route('expenses.categories')" wire:navigate>Manage categories</flux:button>@endcan
            </flux:callout>
        @endif
        <x-app.panel title="Expense details">
            <form wire:submit="save" class="grid gap-5 p-5">
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <flux:input label="Expense date" type="date" wire:model="form.expense_date" required />
                    <flux:select label="Category" wire:model="form.expense_category_id" required>
                        <option value="">Select category</option>
                        @foreach ($this->categories as $category)<option wire:key="entry-category-{{ $category->id }}" value="{{ $category->id }}">{{ $category->name }}</option>@endforeach
                    </flux:select>
                    <flux:input :label="'Amount ('.app(SystemSettings::class)->currency().')'" inputmode="decimal" wire:model="form.amount" placeholder="0.00" required />
                </div>
                <flux:textarea label="Description" wire:model="form.description" rows="2" required />
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input label="Payee (optional)" wire:model="form.payee" />
                    <flux:select label="Payment method (optional)" wire:model="form.payment_method">
                        <option value="">Not specified</option>
                        @foreach (PaymentMethod::cases() as $method)<option wire:key="entry-method-{{ $method->value }}" value="{{ $method->value }}">{{ $method->label() }}</option>@endforeach
                    </flux:select>
                    <flux:select label="Academic year (optional)" wire:model.live="form.academic_year_id">
                        <option value="">Outside academic periods</option>
                        @foreach ($this->years as $year)<option wire:key="entry-year-{{ $year->id }}" value="{{ $year->id }}">{{ $year->name }}</option>@endforeach
                    </flux:select>
                    <flux:select label="Term (optional)" wire:model="form.term_id" :disabled="$form['academic_year_id'] === ''">
                        <option value="">No specific term</option>
                        @foreach ($this->terms as $term)<option wire:key="entry-term-{{ $term->id }}" value="{{ $term->id }}">{{ $term->name }}</option>@endforeach
                    </flux:select>
                </div>
                <flux:input label="Reference (optional)" wire:model="form.reference" />
                <flux:textarea label="Notes (optional)" wire:model="form.notes" rows="2" />
                <flux:error name="form.expense" />
                <flux:text>Recorded expenses are preserved. To correct one, void it with a reason and record its replacement.</flux:text>
                <div class="flex flex-wrap gap-3">
                    <flux:button variant="primary" type="submit" wire:loading.attr="disabled">Record expense</flux:button>
                    <flux:button type="button" wire:click="save(false)" wire:loading.attr="disabled">Save draft</flux:button>
                </div>
            </form>
        </x-app.panel>
    @endif
</x-finance.layout>
