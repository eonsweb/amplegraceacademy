<?php

use App\Actions\Expenses\ManageExpenseCategory;
use App\Models\ExpenseCategory;
use App\Support\Settings\SystemSettings;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

return new #[Title('Expense Categories')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $name = '';
    public string $description = '';
    public bool $isActive = true;
    public bool $showForm = false;
    #[Locked]
    public ?int $editingId = null;

    public function boot(): void
    {
        Gate::authorize('viewAny', ExpenseCategory::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, ExpenseCategory> */
    #[Computed]
    public function categories(): LengthAwarePaginator
    {
        return ExpenseCategory::query()->where('name', 'like', mb_substr(trim($this->search), 0, 100).'%')
            ->withCount('expenses')->orderBy('name')->orderBy('id')->paginate(app(SystemSettings::class)->recordsPerPage());
    }

    public function create(): void
    {
        Gate::authorize('create', ExpenseCategory::class);
        $this->reset('editingId', 'name', 'description', 'isActive');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $category = ExpenseCategory::query()->findOrFail($id);
        Gate::authorize('update', $category);
        $this->editingId = $id;
        $this->name = $category->name;
        $this->description = $category->description ?? '';
        $this->isActive = $category->is_active;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->resetValidation();
        app(ManageExpenseCategory::class)->save(auth()->user(), $this->editingId, $this->name, $this->description, $this->isActive);
        $this->showForm = false;
        unset($this->categories);
        \Flux\Flux::toast(variant: 'success', text: 'Expense category saved.');
    }

    public function delete(int $id): void
    {
        $this->resetValidation('delete');
        app(ManageExpenseCategory::class)->delete(auth()->user(), $id);
        unset($this->categories);
        \Flux\Flux::toast(variant: 'success', text: 'Unused category deleted.');
    }
};
?>
<x-finance.layout heading="Expense Categories" subheading="Deactivate categories with expense history instead of deleting them.">
    <div class="flex flex-wrap items-end gap-3">
        <flux:input class="flex-1" label="Search categories" wire:model.live.debounce.400ms="search" />
        <flux:button variant="primary" wire:click="create">Add category</flux:button>
    </div>
    <flux:error name="delete" />
    <x-app.panel title="Categories">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr><th scope="col" class="p-4">Name</th><th scope="col" class="p-4">Status</th><th scope="col" class="hidden p-4 sm:table-cell">Expenses</th><th scope="col" class="p-4">Actions</th></tr></thead>
                <tbody>
                    @forelse ($this->categories as $category)
                        <tr wire:key="category-{{ $category->id }}" class="border-t border-zinc-200 dark:border-zinc-700">
                            <td class="p-4">{{ $category->name }}<p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $category->description }}</p></td>
                            <td class="p-4">{{ $category->is_active ? 'Active' : 'Inactive' }}</td>
                            <td class="hidden p-4 sm:table-cell">{{ $category->expenses_count }}</td>
                            <td class="p-4"><div class="flex flex-wrap gap-2">
                                <flux:button size="sm" wire:click="edit({{ $category->id }})">Edit</flux:button>
                                @if ($category->expenses_count === 0)<flux:button size="sm" variant="danger" wire:click="delete({{ $category->id }})" wire:confirm="Delete this unused category?" wire:loading.attr="disabled">Delete</flux:button>@endif
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-8 text-center">No categories found. Add a category to start recording expenses.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $this->categories->links() }}</div>
    </x-app.panel>
    <flux:modal wire:model="showForm" class="max-w-lg">
        <form wire:submit="save" class="grid gap-4">
            <flux:heading size="lg">{{ $editingId ? 'Edit category' : 'Add category' }}</flux:heading>
            <flux:input label="Name" wire:model="name" required />
            <flux:error name="normalized_name" />
            <flux:textarea label="Description (optional)" wire:model="description" />
            <flux:switch label="Active" wire:model="isActive" />
            <flux:button variant="primary" type="submit" wire:loading.attr="disabled">Save category</flux:button>
        </form>
    </flux:modal>
</x-finance.layout>
