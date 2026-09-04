<?php

use App\Models\ClassLevel;
use App\Support\Authorization\Permissions;
use App\Support\Settings\SystemSettings;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Class Levels')] class extends Component {
    use WithPagination;
    #[Url] public string $search = '';
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public int $levelOrder = 1;
    public string $description = '';
    public bool $isActive = true;
    public int $recordsPerPage = 25;

    public function mount(SystemSettings $settings): void { Gate::authorize(Permissions::CLASSES_VIEW); $this->recordsPerPage = $settings->recordsPerPage(); }
    public function updatedSearch(): void { $this->resetPage(); }

    /** @return LengthAwarePaginator<int, ClassLevel> */
    #[Computed]
    public function levels(): LengthAwarePaginator
    {
        $search = trim($this->search);
        return ClassLevel::query()->select(['id', 'name', 'level_order', 'description', 'is_active'])->withCount('classSubjects')
            ->when($search !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('level_order')->orderBy('id')->paginate($this->recordsPerPage);
    }

    public function create(): void { Gate::authorize(Permissions::CLASSES_CREATE); $this->resetForm(); $this->levelOrder = ((int) ClassLevel::query()->max('level_order')) + 1; $this->showForm = true; }
    public function edit(int $id): void
    {
        Gate::authorize(Permissions::CLASSES_UPDATE); $level = ClassLevel::query()->findOrFail($id);
        $this->editingId = $level->id; $this->name = $level->name; $this->levelOrder = $level->level_order; $this->description = $level->description ?? ''; $this->isActive = $level->is_active; $this->showForm = true;
    }
    public function save(): void
    {
        Gate::authorize($this->editingId === null ? Permissions::CLASSES_CREATE : Permissions::CLASSES_UPDATE);
        $level = $this->editingId === null ? null : ClassLevel::query()->findOrFail($this->editingId);
        $validated = $this->validate(['name' => ['required', 'string', 'max:100', Rule::unique(ClassLevel::class, 'name')->ignore($level)], 'levelOrder' => ['required', 'integer', 'between:1,999', Rule::unique(ClassLevel::class, 'level_order')->ignore($level)], 'description' => ['nullable', 'string', 'max:1000'], 'isActive' => ['boolean']]);
        ($level ?? new ClassLevel)->fill(['name' => trim($validated['name']), 'level_order' => $validated['levelOrder'], 'description' => filled($validated['description']) ? trim($validated['description']) : null, 'is_active' => $validated['isActive']])->save();
        $this->showForm = false; $this->resetForm(); unset($this->levels); Flux::toast(variant: 'success', text: 'Class level saved.');
    }
    public function toggle(int $id): void { Gate::authorize(Permissions::CLASSES_UPDATE); $level = ClassLevel::query()->findOrFail($id); $level->update(['is_active' => ! $level->is_active]); unset($this->levels); Flux::toast(variant: 'success', text: $level->is_active ? 'Class level activated.' : 'Class level deactivated.'); }
    public function delete(int $id): void
    {
        Gate::authorize(Permissions::CLASSES_DELETE); $level = ClassLevel::query()->findOrFail($id);
        if ($level->classSubjects()->exists()) { $this->addError('level', 'This level has subject assignments. Remove them first.'); return; }
        $level->delete(); unset($this->levels); Flux::toast(variant: 'success', text: 'Class level deleted.');
    }
    private function resetForm(): void { $this->reset('editingId', 'name', 'description'); $this->levelOrder = 1; $this->isActive = true; $this->resetValidation(); }
};
?>

<x-academic.layout heading="Class Levels" subheading="Configure the school's grade or academic level structure.">
    <div class="grid gap-4"><div class="flex flex-col gap-3 sm:flex-row sm:justify-between"><flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Search levels..." class="max-w-sm" />@can(Permissions::CLASSES_CREATE)<flux:button variant="primary" icon="plus" wire:click="create">Create Class Level</flux:button>@endcan</div>@error('level')<flux:callout variant="danger" heading="{{ $message }}" />@enderror
        <x-app.panel title="Ordered class levels"><div class="overflow-x-auto"><table class="w-full min-w-lg text-left text-sm"><thead class="border-b text-xs uppercase text-zinc-500"><tr><th class="px-4 py-3">Order</th><th class="px-4 py-3">Level</th><th class="px-4 py-3">Description</th><th class="px-4 py-3">Subjects</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Actions</th></tr></thead><tbody class="divide-y dark:divide-zinc-800">@forelse($this->levels as $level)<tr wire:key="level-{{ $level->id }}"><td class="px-4 py-3">{{ $level->level_order }}</td><td class="px-4 py-3 font-semibold">{{ $level->name }}</td><td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $level->description ?? '—' }}</td><td class="px-4 py-3">{{ $level->class_subjects_count }}</td><td class="px-4 py-3"><flux:badge :color="$level->is_active ? 'green' : 'zinc'">{{ $level->is_active ? 'Active' : 'Inactive' }}</flux:badge></td><td class="px-4 py-3"><div class="flex justify-end gap-2">@can(Permissions::CLASSES_UPDATE)<flux:button size="sm" variant="ghost" wire:click="toggle({{ $level->id }})">{{ $level->is_active ? 'Deactivate' : 'Activate' }}</flux:button><flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $level->id }})" />@endcan @can(Permissions::CLASSES_DELETE)<flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $level->id }})" wire:confirm="Delete this class level?" />@endcan</div></td></tr>@empty<tr><td colspan="6"><x-academic.empty-state class="border-0" icon="academic-cap" heading="No class levels configured" description="Create class levels in teaching order." /></td></tr>@endforelse</tbody></table></div>@if($this->levels->hasPages())<div class="border-t p-4">{{ $this->levels->links() }}</div>@endif</x-app.panel>
    </div>
    <flux:modal wire:model.self="showForm" class="max-w-lg"><form wire:submit="save" class="grid gap-5"><flux:heading size="lg">{{ $editingId === null ? 'Create Class Level' : 'Edit Class Level' }}</flux:heading><div class="grid gap-4 sm:grid-cols-[1fr_8rem]"><flux:input wire:model="name" label="Name" placeholder="Primary 5" required /><flux:input wire:model="levelOrder" type="number" min="1" max="999" label="Order" required /></div><flux:textarea wire:model="description" label="Description" rows="3" /><flux:switch wire:model="isActive" label="Active" /><div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Save</flux:button></div></form></flux:modal>
</x-academic.layout>
