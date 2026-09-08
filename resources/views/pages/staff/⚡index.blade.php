<?php

use App\Models\Staff;
use App\StaffStatus;
use App\Support\Authorization\Permissions;
use App\Support\Settings\SystemSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Staff')] class extends Component {
    use WithPagination;

    #[Url] public string $search = '';
    #[Url] public string $statusFilter = '';
    #[Url] public string $departmentFilter = '';
    #[Url] public string $accessFilter = '';
    public int $recordsPerPage = 25;

    public function mount(SystemSettings $settings): void
    {
        Gate::authorize('viewAny', Staff::class);
        $this->recordsPerPage = $settings->recordsPerPage();
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }
    public function updatedDepartmentFilter(): void { $this->resetPage(); }
    public function updatedAccessFilter(): void { $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->reset('search', 'statusFilter', 'departmentFilter', 'accessFilter');
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Staff> */
    #[Computed]
    public function staffMembers(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Staff::query()
            ->select(['id', 'staff_number', 'first_name', 'last_name', 'role_title', 'department', 'phone', 'status'])
            ->withExists('user')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->where('staff_number', 'like', '%'.$search.'%')
                        ->orWhere('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('role_title', 'like', '%'.$search.'%');

                    $nameParts = preg_split('/\s+/', $search, 2);
                    if (count($nameParts) === 2) {
                        $searchQuery->orWhere(function (Builder $nameQuery) use ($nameParts): void {
                            $nameQuery->where('first_name', 'like', '%'.$nameParts[0].'%')->where('last_name', 'like', '%'.$nameParts[1].'%');
                        });
                    }
                });
            })
            ->when($this->statusFilter !== '', fn (Builder $query): Builder => $query->where('status', $this->statusFilter))
            ->when($this->departmentFilter !== '', fn (Builder $query): Builder => $query->where('department', $this->departmentFilter))
            ->when($this->accessFilter === 'yes', fn (Builder $query): Builder => $query->whereHas('user'))
            ->when($this->accessFilter === 'no', fn (Builder $query): Builder => $query->whereDoesntHave('user'))
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->paginate($this->recordsPerPage);
    }

    /** @return Collection<int, string> */
    #[Computed]
    public function departments(): Collection
    {
        return Staff::query()->whereNotNull('department')->where('department', '!=', '')
            ->distinct()->orderBy('department')->limit(100)->pluck('department');
    }
};
?>

<div class="grid gap-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div><flux:heading size="xl">Staff</flux:heading><flux:text class="mt-1">Manage school employees and their application access.</flux:text></div>
        @can(Permissions::STAFF_CREATE)<flux:button :href="route('staff.create')" wire:navigate variant="primary" icon="plus">Add Staff</flux:button>@endcan
    </div>
    <x-app.panel title="Staff directory">
        <div class="grid gap-3 border-b border-zinc-200 p-4 dark:border-zinc-800 md:grid-cols-2 xl:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))_auto]">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Name, staff no., phone, email, or position" aria-label="Search staff" />
            <flux:select wire:model.live="statusFilter" aria-label="Filter staff by status"><option value="">All statuses</option>@foreach(StaffStatus::cases() as $status)<option value="{{ $status->value }}">{{ $status->label() }}</option>@endforeach</flux:select>
            <flux:select wire:model.live="departmentFilter" aria-label="Filter staff by department"><option value="">All departments</option>@foreach($this->departments as $department)<option value="{{ $department }}">{{ $department }}</option>@endforeach</flux:select>
            <flux:select wire:model.live="accessFilter" aria-label="Filter staff by application access"><option value="">All access</option><option value="yes">Has account</option><option value="no">No account</option></flux:select>
            @if($search !== '' || $statusFilter !== '' || $departmentFilter !== '' || $accessFilter !== '')<flux:button wire:click="resetFilters" variant="ghost">Clear</flux:button>@endif
        </div>
        <div class="hidden overflow-x-auto md:block"><table class="w-full min-w-4xl text-left text-sm">
            <caption class="sr-only">Staff directory</caption>
            <thead class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-800"><tr><th class="px-4 py-3">Staff</th><th class="px-4 py-3">Staff no.</th><th class="px-4 py-3">Position</th><th class="px-4 py-3">Phone</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Access</th><th class="px-4 py-3 text-right">Actions</th></tr></thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">@forelse($this->staffMembers as $staff)
                <tr wire:key="staff-{{ $staff->id }}"><td class="px-4 py-3 font-semibold">{{ $staff->fullName() }}</td><td class="px-4 py-3 font-mono text-xs">{{ $staff->staff_number }}</td><td class="px-4 py-3"><p>{{ $staff->role_title }}</p><p class="text-xs text-zinc-500">{{ $staff->department ?? 'No department' }}</p></td><td class="px-4 py-3">{{ $staff->phone ?? '—' }}</td><td class="px-4 py-3"><flux:badge :color="$staff->status === StaffStatus::Active ? 'green' : 'zinc'">{{ $staff->status->label() }}</flux:badge></td><td class="px-4 py-3"><flux:badge :color="$staff->user_exists ? 'blue' : 'zinc'">{{ $staff->user_exists ? 'User' : 'No account' }}</flux:badge></td><td class="px-4 py-3"><div class="flex justify-end gap-2"><flux:button size="sm" variant="ghost" :href="route('staff.show', $staff)" wire:navigate>View</flux:button>@can('update', $staff)<flux:button size="sm" variant="ghost" icon="pencil-square" :href="route('staff.edit', $staff)" wire:navigate aria-label="Edit {{ $staff->fullName() }}" />@endcan</div></td></tr>
            @empty<tr><td colspan="7" class="px-4 py-12 text-center text-zinc-500">{{ $search !== '' || $statusFilter !== '' || $departmentFilter !== '' || $accessFilter !== '' ? 'No staff members match the current filters.' : 'No staff members have been added yet.' }}</td></tr>@endforelse</tbody>
        </table></div>
        <div class="grid divide-y divide-zinc-200 md:hidden dark:divide-zinc-800">@forelse($this->staffMembers as $staff)
            <article wire:key="staff-card-{{ $staff->id }}" class="grid gap-3 p-4"><div class="flex items-start justify-between gap-3"><div><p class="font-semibold">{{ $staff->fullName() }}</p><p class="font-mono text-xs text-zinc-500">{{ $staff->staff_number }}</p></div><flux:badge :color="$staff->status === StaffStatus::Active ? 'green' : 'zinc'">{{ $staff->status->label() }}</flux:badge></div><div><p>{{ $staff->role_title }}</p><p class="text-sm text-zinc-500">{{ $staff->department ?? 'No department' }} · {{ $staff->phone ?? 'No phone' }}</p></div><div class="flex items-center justify-between"><span class="text-sm text-zinc-500">{{ $staff->user_exists ? 'Application account linked' : 'No application account' }}</span><flux:button size="sm" variant="ghost" :href="route('staff.show', $staff)" wire:navigate>View</flux:button></div></article>
        @empty<div class="p-10 text-center text-zinc-500">{{ $search !== '' || $statusFilter !== '' || $departmentFilter !== '' || $accessFilter !== '' ? 'No staff members match the current filters.' : 'No staff members have been added yet.' }}</div>@endforelse</div>
        @if($this->staffMembers->hasPages())<div class="border-t border-zinc-200 p-4 dark:border-zinc-800">{{ $this->staffMembers->links() }}</div>@endif
    </x-app.panel>
</div>
