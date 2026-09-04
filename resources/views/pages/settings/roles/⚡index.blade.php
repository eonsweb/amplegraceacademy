<?php

use App\Events\AuthorizationChanged;
use App\Support\Authorization\AuthorizationSafety;
use App\Support\Authorization\Permissions;
use App\Support\Settings\SystemSettings;
use App\Support\Authorization\Roles;
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
use Spatie\Permission\Models\Role;

new #[Title('Roles & permissions')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    public string $newRoleName = '';

    public bool $showCreateRole = false;

    public int $recordsPerPage = 25;

    public function mount(SystemSettings $settings): void
    {
        abort_unless(Gate::any([Permissions::ROLES_VIEW, Permissions::PERMISSIONS_VIEW]), 403);
        $this->recordsPerPage = $settings->recordsPerPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Role> */
    #[Computed]
    public function roles(): LengthAwarePaginator
    {
        return Role::query()
            ->select(['id', 'name', 'guard_name'])
            ->withCount(['users', 'permissions'])
            ->when($this->search !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('name')
            ->paginate($this->recordsPerPage);
    }

    public function createRole(): void
    {
        Gate::authorize(Permissions::ROLES_CREATE);

        $this->newRoleName = trim($this->newRoleName);
        $validated = $this->validate([
            'newRoleName' => [
                'required', 'string', 'max:100',
                Rule::unique(config('permission.table_names.roles'), 'name')->where('guard_name', 'web'),
            ],
        ], attributes: ['newRoleName' => 'role name']);

        $role = Role::create(['name' => trim($validated['newRoleName']), 'guard_name' => 'web']);

        AuthorizationChanged::dispatch('role.created', auth()->id(), Role::class, $role->id, ['name' => $role->name]);

        $this->reset('newRoleName', 'showCreateRole');
        unset($this->roles);
        Flux::toast(variant: 'success', text: 'Role created.');
    }

    public function deleteRole(int $roleId, AuthorizationSafety $safety): void
    {
        Gate::authorize(Permissions::ROLES_DELETE);

        $role = Role::query()->where('guard_name', 'web')->findOrFail($roleId);
        $safety->ensureRoleMayBeDeleted($role);

        $roleName = $role->name;
        $role->delete();

        AuthorizationChanged::dispatch('role.deleted', auth()->id(), Role::class, $roleId, ['name' => $roleName]);

        unset($this->roles);
        Flux::toast(variant: 'success', text: 'Role deleted.');
    }

    public function isInitialRole(Role $role): bool
    {
        return in_array($role->name, Roles::initial(), true);
    }
};
?>

<section class="grid gap-6">
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
        <div>
            <flux:heading size="xl">Roles & Permissions</flux:heading>
            <flux:subheading class="mt-1">Create flexible roles and control the actions each role can perform.</flux:subheading>
        </div>
        @can(Permissions::ROLES_CREATE)
            <flux:button variant="primary" icon="plus" wire:click="$set('showCreateRole', true)">Create role</flux:button>
        @endcan
    </div>

    @error('role')
        <flux:callout variant="danger" icon="exclamation-circle" heading="{{ $message }}" />
    @enderror

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-4 dark:border-zinc-800">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search roles..." aria-label="Search roles" />
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-left text-sm">
                <caption class="sr-only">Application roles with assigned users and permissions</caption>
                <thead class="bg-zinc-50 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:bg-zinc-800 dark:text-zinc-300">
                    <tr>
                        <th scope="col" class="px-4 py-3">Role</th>
                        <th scope="col" class="px-4 py-3">Users</th>
                        <th scope="col" class="px-4 py-3">Permissions</th>
                        <th scope="col" class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($this->roles as $role)
                        <tr wire:key="role-{{ $role->id }}">
                            <td class="px-4 py-3">
                                <div class="font-semibold text-zinc-900 dark:text-white">{{ $role->name }}</div>
                                @if ($this->isInitialRole($role))
                                    <span class="text-xs text-zinc-500">Initial school role</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $role->users_count }}</td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $role->permissions_count }}</td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" variant="ghost" :href="route('roles.edit', $role)" wire:navigate>
                                        {{ auth()->user()->can(Permissions::ROLES_UPDATE) ? 'Edit' : 'View' }}
                                    </flux:button>
                                    @can(Permissions::ROLES_DELETE)
                                        @if (! $this->isInitialRole($role))
                                            <flux:button size="sm" variant="danger" wire:click="deleteRole({{ $role->id }})" wire:confirm="Delete this role? This cannot be undone.">Delete</flux:button>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-10 text-center text-zinc-500">No roles match your search.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->roles->hasPages())
            <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">{{ $this->roles->links() }}</div>
        @endif
    </div>

    <flux:modal wire:model.self="showCreateRole" class="max-w-lg">
        <form wire:submit="createRole" class="grid gap-6">
            <div>
                <flux:heading size="lg">Create a new role</flux:heading>
                <flux:subheading class="mt-1">You can assign its permissions after creation.</flux:subheading>
            </div>
            <flux:input wire:model="newRoleName" label="Role name" placeholder="e.g. Accountant" required maxlength="100" />
            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showCreateRole', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Create role</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
