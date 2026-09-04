<?php

use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('User access')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize(Permissions::USERS_VIEW);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, User> */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        return User::query()
            ->select(['id', 'name', 'username', 'email'])
            ->with('roles:id,name,guard_name')
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $searchQuery): void {
                    $searchQuery
                        ->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('username', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(10);
    }
};
?>

<section class="grid gap-6">
    <div>
        <flux:heading size="xl">User Access</flux:heading>
        <flux:subheading class="mt-1">Review each user’s roles and manage individual access when needed.</flux:subheading>
    </div>

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm">
        <div class="border-b border-zinc-200 p-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search by name, username, or email..." aria-label="Search users" />
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[700px] text-left text-sm">
                <caption class="sr-only">Users and their assigned authorization roles</caption>
                <thead class="bg-zinc-50 text-xs font-semibold uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th scope="col" class="px-4 py-3">User</th>
                        <th scope="col" class="px-4 py-3">Username</th>
                        <th scope="col" class="px-4 py-3">Roles</th>
                        <th scope="col" class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200">
                    @forelse ($this->users as $user)
                        <tr wire:key="user-{{ $user->id }}">
                            <td class="px-4 py-3">
                                <p class="font-semibold text-zinc-900">{{ $user->name }}</p>
                                <p class="text-xs text-zinc-500">{{ $user->email }}</p>
                            </td>
                            <td class="px-4 py-3 text-zinc-600">{{ $user->username }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    @forelse ($user->roles as $role)
                                        <flux:badge size="sm" wire:key="user-{{ $user->id }}-role-{{ $role->id }}">{{ $role->name }}</flux:badge>
                                    @empty
                                        <span class="text-xs text-amber-700">No role assigned</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <flux:button size="sm" variant="ghost" :href="route('users.authorization', $user)" wire:navigate>
                                    {{ auth()->user()->can(Permissions::USERS_UPDATE) && auth()->user()->can(Permissions::PERMISSIONS_ASSIGN) ? 'Manage access' : 'View access' }}
                                </flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-10 text-center text-zinc-500">No users match your search.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->users->hasPages())
            <div class="border-t border-zinc-200 p-4">{{ $this->users->links() }}</div>
        @endif
    </div>
</section>
