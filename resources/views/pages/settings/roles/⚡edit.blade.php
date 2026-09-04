<?php

use App\Events\AuthorizationChanged;
use App\Support\Authorization\AuthorizationSafety;
use App\Support\Authorization\Permissions;
use App\Support\Authorization\Roles;
use Flux\Flux;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

new #[Title('Edit role')] class extends Component {
    public Role $role;

    public string $name = '';

    /** @var list<string> */
    public array $permissionNames = [];

    public function mount(Role $role): void
    {
        abort_unless(Gate::any([Permissions::ROLES_VIEW, Permissions::PERMISSIONS_VIEW]), 403);

        abort_unless($role->guard_name === 'web', 404);

        $this->role = $role;
        $this->name = $role->name;
        $this->permissionNames = $role->permissions()->orderBy('name')->pluck('name')->all();
    }

    /** @return array<string, array<string, string>> */
    #[Computed]
    public function permissionGroups(): array
    {
        return Permissions::grouped();
    }

    #[Computed]
    public function isInitialRole(): bool
    {
        return in_array($this->role->name, Roles::initial(), true);
    }

    public function selectModule(string $module): void
    {
        Gate::authorize(Permissions::ROLES_UPDATE);
        Gate::authorize(Permissions::PERMISSIONS_ASSIGN);

        $permissions = $this->permissionGroups[$module] ?? abort(404);
        $this->permissionNames = collect($this->permissionNames)
            ->merge(array_keys($permissions))
            ->unique()
            ->values()
            ->all();
    }

    public function clearModule(string $module): void
    {
        Gate::authorize(Permissions::ROLES_UPDATE);
        Gate::authorize(Permissions::PERMISSIONS_ASSIGN);

        $permissions = array_keys($this->permissionGroups[$module] ?? abort(404));
        $this->permissionNames = array_values(array_diff($this->permissionNames, $permissions));
    }

    public function save(AuthorizationSafety $safety): void
    {
        Gate::authorize(Permissions::ROLES_UPDATE);

        $this->name = trim($this->name);
        $validated = $this->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique(config('permission.table_names.roles'), 'name')
                    ->where(fn (Builder $query): Builder => $query->where('guard_name', 'web'))
                    ->ignore($this->role->id),
            ],
            'permissionNames' => ['array'],
            'permissionNames.*' => [
                'string', 'distinct',
                Rule::exists(config('permission.table_names.permissions'), 'name')
                    ->where(fn (Builder $query): Builder => $query->where('guard_name', 'web')),
            ],
        ]);

        $safety->ensureRoleNameMayChange($this->role, $validated['name']);

        $currentPermissions = $this->role->permissions()->orderBy('name')->pluck('name')->all();
        $submittedPermissions = collect($validated['permissionNames'])->sort()->values()->all();
        $permissionsChanged = $currentPermissions !== $submittedPermissions;

        if ($permissionsChanged) {
            Gate::authorize(Permissions::PERMISSIONS_ASSIGN);
            $safety->ensurePermissionsMayBeGranted(auth()->user(), $submittedPermissions);
            $safety->ensureAdministrativeAccessRemainsAfterRoleSync($this->role, $submittedPermissions);
        }

        $oldName = $this->role->name;
        $this->role->update(['name' => $validated['name']]);

        if ($permissionsChanged) {
            $this->role->syncPermissions($submittedPermissions);
        }

        if ($oldName !== $this->role->name) {
            AuthorizationChanged::dispatch('role.updated', auth()->id(), Role::class, $this->role->id, [
                'old_name' => $oldName,
                'name' => $this->role->name,
            ]);
        }

        foreach (array_diff($submittedPermissions, $currentPermissions) as $permission) {
            AuthorizationChanged::dispatch('role.permission_assigned', auth()->id(), Role::class, $this->role->id, [
                'permission' => $permission,
            ]);
        }

        foreach (array_diff($currentPermissions, $submittedPermissions) as $permission) {
            AuthorizationChanged::dispatch('role.permission_removed', auth()->id(), Role::class, $this->role->id, [
                'permission' => $permission,
            ]);
        }

        Flux::toast(variant: 'success', text: 'Role updated.');
    }
};
?>

<section class="grid gap-6">
    <div class="flex items-center gap-3">
        <flux:button variant="ghost" icon="arrow-left" :href="route('roles.index')" wire:navigate aria-label="Back to roles" />
        <div>
            <flux:heading size="xl">{{ auth()->user()->can(Permissions::ROLES_UPDATE) ? 'Edit role' : 'Role permissions' }}</flux:heading>
            <flux:subheading class="mt-1">Permissions are grouped by school function for easier review.</flux:subheading>
        </div>
    </div>

    <form wire:submit="save" class="grid gap-6">
        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
            <flux:input wire:model="name" label="Role name" maxlength="100" required :disabled="! auth()->user()->can(Permissions::ROLES_UPDATE) || $this->isInitialRole" />
            @if ($this->isInitialRole)
                <p class="mt-2 text-xs text-zinc-500">Initial role names are fixed so repeated seeding remains safe.</p>
            @endif
        </div>

        @error('authorization')
            <flux:callout variant="danger" icon="exclamation-circle" heading="{{ $message }}" />
        @enderror
        @error('permissionNames')
            <flux:callout variant="danger" icon="exclamation-circle" heading="{{ $message }}" />
        @enderror

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->permissionGroups as $module => $permissions)
                <fieldset wire:key="permission-module-{{ str($module)->slug() }}" class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between gap-3 border-b border-zinc-100 pb-3">
                        <legend class="font-semibold text-zinc-900">{{ $module }}</legend>
                        @can(Permissions::PERMISSIONS_ASSIGN)
                            @can(Permissions::ROLES_UPDATE)
                                <span class="flex gap-2 text-xs">
                                    <button type="button" class="font-medium text-brand-700 hover:underline" wire:click="selectModule('{{ $module }}')">Select all</button>
                                    <button type="button" class="text-zinc-500 hover:underline" wire:click="clearModule('{{ $module }}')">Clear</button>
                                </span>
                            @endcan
                        @endcan
                    </div>
                    <div class="mt-3 grid gap-3">
                        @foreach ($permissions as $permission => $label)
                            <label wire:key="permission-{{ $permission }}" class="flex items-start gap-3 text-sm">
                                <input type="checkbox" value="{{ $permission }}" wire:model="permissionNames" class="mt-0.5 size-4 rounded border-zinc-300 text-brand-700 focus:ring-brand-600" @disabled(! auth()->user()->can(Permissions::PERMISSIONS_ASSIGN) || ! auth()->user()->can(Permissions::ROLES_UPDATE))>
                                <span class="min-w-0">
                                    <span class="font-medium text-zinc-800">{{ $label }}</span>
                                    @if (Permissions::isPowerful($permission))
                                        <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-700">Sensitive</span>
                                    @endif
                                    <span class="block text-xs text-zinc-500">{{ $permission }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
        </div>

        @can(Permissions::ROLES_UPDATE)
            <div class="sticky bottom-4 flex justify-end">
                <flux:button type="submit" variant="primary">Save role</flux:button>
            </div>
        @endcan
    </form>
</section>
