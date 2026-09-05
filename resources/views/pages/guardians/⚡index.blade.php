<?php

use App\Models\Guardian;
use App\Support\Authorization\Permissions;
use App\Support\Settings\SystemSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Guardians / Parents')] class extends Component {
    use WithPagination;

    #[Url] public string $search = '';
    #[Url] public string $sort = 'name';
    public int $recordsPerPage = 25;

    public function mount(SystemSettings $settings): void
    {
        Gate::authorize('viewAny', Guardian::class);
        $this->recordsPerPage = $settings->recordsPerPage();
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedSort(): void { $this->resetPage(); }

    /** @return LengthAwarePaginator<int, Guardian> */
    #[Computed]
    public function guardians(): LengthAwarePaginator
    {
        $terms = str($this->search)->trim()->squish()->explode(' ')->filter()->take(5);

        return Guardian::query()
            ->select(['id', 'title', 'first_name', 'middle_name', 'last_name', 'phone', 'email', 'created_at'])
            ->withCount('students')
            ->when($terms->isNotEmpty(), function (Builder $query) use ($terms): void {
                $query->where(function (Builder $searchQuery) use ($terms): void {
                    foreach ($terms as $term) {
                        $searchQuery->where(function (Builder $termQuery) use ($term): void {
                            $termQuery->where('first_name', 'like', '%'.$term.'%')
                                ->orWhere('middle_name', 'like', '%'.$term.'%')
                                ->orWhere('last_name', 'like', '%'.$term.'%')
                                ->orWhere('phone', 'like', '%'.$term.'%')
                                ->orWhere('email', 'like', '%'.$term.'%')
                                ->orWhereHas('students', fn (Builder $studentQuery): Builder => $studentQuery
                                    ->where('admission_number', 'like', '%'.$term.'%')
                                    ->orWhere('first_name', 'like', '%'.$term.'%')
                                    ->orWhere('middle_name', 'like', '%'.$term.'%')
                                    ->orWhere('last_name', 'like', '%'.$term.'%'));
                        });
                    }
                });
            })
            ->when($this->sort === 'newest', fn (Builder $query): Builder => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->when($this->sort === 'students', fn (Builder $query): Builder => $query->orderByDesc('students_count')->orderBy('last_name')->orderBy('id'))
            ->when(! in_array($this->sort, ['newest', 'students'], true), fn (Builder $query): Builder => $query->orderBy('last_name')->orderBy('first_name')->orderBy('id'))
            ->paginate($this->recordsPerPage);
    }
};
?>

<div class="grid gap-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><flux:heading size="xl">Guardians / Parents</flux:heading><flux:text class="mt-1">Manage guardian contacts and their links to students.</flux:text></div>@can(Permissions::GUARDIANS_CREATE)<flux:button :href="route('guardians.create')" wire:navigate variant="primary" icon="plus">Add Guardian</flux:button>@endcan</div>
    <x-app.panel title="Guardian directory">
        <div class="grid gap-3 border-b border-zinc-200 p-4 dark:border-zinc-800 sm:grid-cols-[minmax(0,1fr)_12rem]"><flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Name, phone, email, student or admission no." /><flux:select wire:model.live="sort" aria-label="Sort guardians"><option value="name">Name</option><option value="newest">Newest</option><option value="students">Most students</option></flux:select></div>
        <div class="overflow-x-auto"><table class="w-full min-w-3xl text-left text-sm"><thead class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-800"><tr><th class="px-4 py-3">Name</th><th class="px-4 py-3">Phone</th><th class="px-4 py-3">Email</th><th class="px-4 py-3">Students</th><th class="px-4 py-3 text-right">Actions</th></tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
            @forelse($this->guardians as $guardian)<tr wire:key="guardian-{{ $guardian->id }}"><td class="px-4 py-3 font-semibold">{{ $guardian->fullName() }}</td><td class="px-4 py-3"><a class="hover:underline" href="tel:{{ $guardian->phone }}">{{ $guardian->phone }}</a></td><td class="px-4 py-3">@if($guardian->email)<a class="hover:underline" href="mailto:{{ $guardian->email }}">{{ $guardian->email }}</a>@else<span class="text-zinc-500">—</span>@endif</td><td class="px-4 py-3"><flux:badge>{{ $guardian->students_count }}</flux:badge></td><td class="px-4 py-3"><div class="flex justify-end gap-2"><flux:button size="sm" variant="ghost" :href="route('guardians.show', $guardian)" wire:navigate>View</flux:button>@can(Permissions::GUARDIANS_UPDATE)<flux:button size="sm" variant="ghost" icon="pencil-square" :href="route('guardians.edit', $guardian)" wire:navigate aria-label="Edit {{ $guardian->fullName() }}" />@endcan</div></td></tr>
            @empty<tr><td colspan="5" class="px-4 py-12 text-center text-zinc-500">{{ filled($search) ? 'No guardians match your search.' : 'No guardians have been added yet.' }}</td></tr>@endforelse
        </tbody></table></div>
        @if($this->guardians->hasPages())<div class="border-t border-zinc-200 p-4 dark:border-zinc-800">{{ $this->guardians->links() }}</div>@endif
    </x-app.panel>
</div>
