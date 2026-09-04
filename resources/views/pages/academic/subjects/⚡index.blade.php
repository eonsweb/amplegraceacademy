<?php

use App\Models\Subject;
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

new #[Title('Subjects')] class extends Component {
    use WithPagination;
    #[Url] public string $search = '';
    #[Url] public string $status = '';
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $code = '';
    public bool $isActive = true;
    public int $recordsPerPage = 25;

    public function mount(SystemSettings $settings): void { Gate::authorize(Permissions::SUBJECTS_VIEW); $this->recordsPerPage = $settings->recordsPerPage(); }
    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }
    /** @return LengthAwarePaginator<int, Subject> */
    #[Computed] public function subjects(): LengthAwarePaginator
    {
        $search = trim($this->search);
        return Subject::query()->select(['id', 'name', 'code', 'is_active'])->withCount('classSubjects')
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $nested): Builder => $nested->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%')))
            ->when($this->status === 'active', fn (Builder $query): Builder => $query->where('is_active', true))->when($this->status === 'inactive', fn (Builder $query): Builder => $query->where('is_active', false))
            ->orderBy('name')->orderBy('id')->paginate($this->recordsPerPage);
    }
    public function create(): void { Gate::authorize(Permissions::SUBJECTS_CREATE); $this->resetForm(); $this->showForm = true; }
    public function edit(int $id): void { Gate::authorize(Permissions::SUBJECTS_UPDATE); $subject = Subject::query()->findOrFail($id); $this->editingId = $subject->id; $this->name = $subject->name; $this->code = $subject->code ?? ''; $this->isActive = $subject->is_active; $this->showForm = true; }
    public function save(): void
    {
        Gate::authorize($this->editingId === null ? Permissions::SUBJECTS_CREATE : Permissions::SUBJECTS_UPDATE);
        $subject = $this->editingId === null ? null : Subject::query()->findOrFail($this->editingId);
        $this->code = strtoupper(trim($this->code));
        $validated = $this->validate(['name' => ['required', 'string', 'max:100', Rule::unique(Subject::class, 'name')->ignore($subject)], 'code' => ['nullable', 'string', 'max:30', Rule::unique(Subject::class, 'code')->ignore($subject)], 'isActive' => ['boolean']]);
        ($subject ?? new Subject)->fill(['name' => trim($validated['name']), 'code' => $validated['code'] ?: null, 'is_active' => $validated['isActive']])->save();
        $this->showForm = false; $this->resetForm(); unset($this->subjects); Flux::toast(variant: 'success', text: 'Subject saved.');
    }
    public function toggle(int $id): void { Gate::authorize(Permissions::SUBJECTS_UPDATE); $subject = Subject::query()->findOrFail($id); $subject->update(['is_active' => ! $subject->is_active]); unset($this->subjects); Flux::toast(variant: 'success', text: $subject->is_active ? 'Subject activated.' : 'Subject deactivated.'); }
    private function resetForm(): void { $this->reset('editingId', 'name', 'code'); $this->isActive = true; $this->resetValidation(); }
};
?>

<x-academic.layout heading="Subjects" subheading="Manage subjects taught by the school.">
    <div class="grid gap-4"><div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem_auto]"><flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Search subject name or code..." /><flux:select wire:model.live="status" aria-label="Filter subjects by status"><flux:select.option value="">All statuses</flux:select.option><flux:select.option value="active">Active</flux:select.option><flux:select.option value="inactive">Inactive</flux:select.option></flux:select>@can(Permissions::SUBJECTS_CREATE)<flux:button variant="primary" icon="plus" wire:click="create">Create Subject</flux:button>@endcan</div>
        <x-app.panel title="Subjects"><div class="overflow-x-auto"><table class="w-full min-w-lg text-left text-sm"><thead class="border-b text-xs uppercase text-zinc-500"><tr><th class="px-4 py-3">Subject name</th><th class="px-4 py-3">Code</th><th class="px-4 py-3">Classes</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Actions</th></tr></thead><tbody class="divide-y dark:divide-zinc-800">@forelse($this->subjects as $subject)<tr wire:key="subject-{{ $subject->id }}"><td class="px-4 py-3 font-semibold">{{ $subject->name }}</td><td class="px-4 py-3">{{ $subject->code ?? '—' }}</td><td class="px-4 py-3">{{ $subject->class_subjects_count }}</td><td class="px-4 py-3"><flux:badge :color="$subject->is_active ? 'green' : 'zinc'">{{ $subject->is_active ? 'Active' : 'Inactive' }}</flux:badge></td><td class="px-4 py-3"><div class="flex justify-end gap-2">@can(Permissions::SUBJECTS_UPDATE)<flux:button size="sm" variant="ghost" wire:click="toggle({{ $subject->id }})" wire:confirm="{{ $subject->is_active ? 'Deactivate this subject? Existing assignments will remain.' : 'Activate this subject?' }}">{{ $subject->is_active ? 'Deactivate' : 'Activate' }}</flux:button><flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $subject->id }})" />@endcan</div></td></tr>@empty<tr><td colspan="5"><x-academic.empty-state class="border-0" icon="building-library" heading="No subjects configured" description="Create subjects, then assign them directly to class levels." /></td></tr>@endforelse</tbody></table></div>@if($this->subjects->hasPages())<div class="border-t p-4">{{ $this->subjects->links() }}</div>@endif</x-app.panel>
    </div>
    <flux:modal wire:model.self="showForm" class="max-w-lg"><form wire:submit="save" class="grid gap-5"><flux:heading size="lg">{{ $editingId === null ? 'Create Subject' : 'Edit Subject' }}</flux:heading><flux:input wire:model="name" label="Subject name" required /><flux:input wire:model="code" label="Code (optional)" maxlength="30" /><flux:switch wire:model="isActive" label="Active" /><div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Save</flux:button></div></form></flux:modal>
</x-academic.layout>
