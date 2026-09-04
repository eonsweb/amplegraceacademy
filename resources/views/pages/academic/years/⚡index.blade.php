<?php

use App\Models\AcademicYear;
use App\Models\Term;
use App\Rules\AcademicYearName;
use App\Support\Academic\AcademicContext;
use App\Support\Authorization\Permissions;
use App\Support\Settings\SystemSettings;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Academic Years')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public bool $isCurrent = false;
    public int $recordsPerPage = 25;

    public function mount(SystemSettings $settings): void
    {
        Gate::authorize(Permissions::CLASSES_VIEW);
        $this->recordsPerPage = $settings->recordsPerPage();
    }

    public function updatedSearch(): void { $this->resetPage(); }

    /** @return LengthAwarePaginator<int, AcademicYear> */
    #[Computed]
    public function years(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return AcademicYear::query()->select(['id', 'name', 'is_current'])
            ->when($search !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.$search.'%'))
            ->orderByDesc('name')->orderByDesc('id')->paginate($this->recordsPerPage);
    }

    public function create(): void
    {
        Gate::authorize(Permissions::CLASSES_CREATE);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        Gate::authorize(Permissions::CLASSES_UPDATE);
        $year = AcademicYear::query()->findOrFail($id);
        $this->editingId = $year->id;
        $this->name = $year->name;
        $this->isCurrent = (bool) $year->is_current;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(AcademicContext $context): void
    {
        Gate::authorize($this->editingId === null ? Permissions::CLASSES_CREATE : Permissions::CLASSES_UPDATE);
        $year = $this->editingId === null ? null : AcademicYear::query()->findOrFail($this->editingId);
        $validated = $this->validate([
            'name' => ['required', 'string', new AcademicYearName, Rule::unique(AcademicYear::class, 'name')->ignore($year)],
            'isCurrent' => ['boolean'],
        ]);
        $isCreating = $year === null;

        DB::transaction(function () use ($context, $isCreating, $validated, &$year): void {
            $year ??= new AcademicYear;
            $year->fill(['name' => trim($validated['name'])])->save();

            if ($isCreating) {
                $now = now();
                $year->terms()->insert(collect(Term::STANDARD_TERMS)->map(
                    fn (string $name, int $termOrder): array => [
                        'academic_year_id' => $year->id,
                        'name' => $name,
                        'term_order' => $termOrder,
                        'is_current' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                )->values()->all());
            }

            if ($validated['isCurrent']) {
                $context->setCurrentYear($year, selectFirstTerm: $isCreating);
            }
        });

        $context->forget();

        $this->showForm = false;
        $this->resetForm();
        unset($this->years);
        Flux::toast(variant: 'success', text: 'Academic year saved.');
    }

    public function makeCurrent(int $id, AcademicContext $context): void
    {
        Gate::authorize(Permissions::CLASSES_UPDATE);
        $context->setCurrentYear(AcademicYear::query()->findOrFail($id));
        unset($this->years);
        Flux::toast(variant: 'success', text: 'Current academic year updated.');
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'isCurrent');
        $this->resetValidation();
    }
};
?>

<x-academic.layout heading="Academic Years" subheading="Manage school academic years and the current academic year.">
    <div class="grid gap-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:justify-between"><flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Search academic years..." class="max-w-sm" />@can(Permissions::CLASSES_CREATE)<flux:button variant="primary" icon="plus" wire:click="create">Create Academic Year</flux:button>@endcan</div>
        <x-app.panel title="Configured academic years">
            <div class="overflow-x-auto"><table class="w-full min-w-xl text-left text-sm">
                <thead class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-800"><tr><th class="px-4 py-3">Academic Year</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Actions</th></tr></thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">@forelse($this->years as $year)
                    <tr wire:key="year-{{ $year->id }}"><td class="px-4 py-3 font-semibold">{{ $year->name }}</td><td class="px-4 py-3">@if($year->is_current)<flux:badge color="green">Current</flux:badge>@else<span class="text-zinc-500">—</span>@endif</td><td class="px-4 py-3"><div class="flex justify-end gap-2">@can(Permissions::CLASSES_UPDATE)@unless($year->is_current)<flux:button size="sm" variant="ghost" wire:click="makeCurrent({{ $year->id }})">Set current</flux:button>@endunless<flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $year->id }})" aria-label="Edit {{ $year->name }}" />@endcan</div></td></tr>
                @empty<tr><td colspan="3"><x-academic.empty-state class="border-0" icon="calendar-days" heading="No academic years configured" description="Create the first academic year to begin configuring terms and assignments." /></td></tr>@endforelse</tbody>
            </table></div>
            @if($this->years->hasPages())<div class="border-t border-zinc-200 p-4 dark:border-zinc-800">{{ $this->years->links() }}</div>@endif
        </x-app.panel>
    </div>
    <flux:modal wire:model.self="showForm" class="max-w-xl"><form wire:submit="save" class="grid gap-5"><flux:heading size="lg">{{ $editingId === null ? 'Create Academic Year' : 'Edit Academic Year' }}</flux:heading><flux:input wire:model="name" label="Academic Year" placeholder="2026/2027" description="Use the YYYY/YYYY format, with consecutive years." required /><flux:switch wire:model="isCurrent" label="Set as current" :disabled="$editingId !== null && $isCurrent" /><div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">{{ $editingId === null ? 'Create Academic Year' : 'Save Changes' }}</flux:button></div></form></flux:modal>
</x-academic.layout>
