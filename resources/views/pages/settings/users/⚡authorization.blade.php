<?php

use App\Events\AuthorizationChanged;
use App\Models\User;
use App\Support\Authorization\AuthorizationSafety;
use App\Support\Authorization\Permissions;
use Flux\Flux;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

new #[Title('Manage user access')] class extends Component {
    public User $user;

    /** @var list<string> */
    public array $roleNames = [];

    /** @var list<string> */
    public array $directPermissionNames = [];

    public function mount(User $user): void
    {
        Gate::authorize(Permissions::USERS_VIEW);

        $this->user = $user;
        $this->loadAssignments();
    }

    /** @return Collection<int, Role> */
    #[Computed]
    public function roles(): Collection
    {
        return Role::query()->where('guard_name', 'web')->orderBy('name')->get(['id', 'name', 'guard_name']);
    }

    /** @return array<string, array<string, string>> */
    #[Computed]
    public function permissionGroups(): array
    {
        return Permissions::grouped();
    }

    /** @return Collection<int, string> */
    #[Computed]
    public function inheritedPermissionNames(): Collection
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $this->roleNames)
            ->with('permissions:id,name')
            ->get()
            ->flatMap(fn (Role $role): Collection => $role->permissions->pluck('name'))
            ->unique()
            ->sort()
            ->values();
    }

    /** @return Collection<int, string> */
    #[Computed]
    public function effectivePermissionNames(): Collection
    {
        return $this->inheritedPermissionNames
            ->merge($this->directPermissionNames)
            ->unique()
            ->sort()
            ->values();
    }

    public function save(AuthorizationSafety $safety): void
    {
        Gate::authorize(Permissions::USERS_UPDATE);
        Gate::authorize(Permissions::PERMISSIONS_ASSIGN);

        $validated = $this->validate([
            'roleNames' => ['array'],
            'roleNames.*' => [
                'string', 'distinct',
                Rule::exists(config('permission.table_names.roles'), 'name')
                    ->where(fn (Builder $query): Builder => $query->where('guard_name', 'web')),
            ],
            'directPermissionNames' => ['array'],
            'directPermissionNames.*' => [
                'string', 'distinct',
                Rule::exists(config('permission.table_names.permissions'), 'name')
                    ->where(fn (Builder $query): Builder => $query->where('guard_name', 'web')),
            ],
        ]);

        $safety->ensureUserMayBeManaged(auth()->user(), $this->user);
        $safety->ensureRolesAndPermissionsMayBeGranted(
            auth()->user(),
            $validated['roleNames'],
            $validated['directPermissionNames'],
        );

        $inheritedPermissions = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $validated['roleNames'])
            ->with('permissions:id,name')
            ->get()
            ->flatMap(fn (Role $role): Collection => $role->permissions->pluck('name'))
            ->unique();

        $directPermissions = collect($validated['directPermissionNames'])
            ->reject(fn (string $permission): bool => $inheritedPermissions->contains($permission))
            ->values()
            ->all();

        $safety->ensureAdministrativeAccessRemains($this->user, $validated['roleNames'], $directPermissions);

        $previousRoles = $this->user->getRoleNames()->all();
        $previousDirectPermissions = $this->user->getDirectPermissions()->pluck('name')->all();

        DB::transaction(function () use ($validated, $directPermissions): void {
            $this->user->syncRoles($validated['roleNames']);
            $this->user->syncPermissions($directPermissions);
        });

        foreach (array_diff($validated['roleNames'], $previousRoles) as $role) {
            AuthorizationChanged::dispatch('user.role_assigned', auth()->id(), User::class, $this->user->id, ['role' => $role]);
        }

        foreach (array_diff($previousRoles, $validated['roleNames']) as $role) {
            AuthorizationChanged::dispatch('user.role_removed', auth()->id(), User::class, $this->user->id, ['role' => $role]);
        }

        foreach (array_diff($directPermissions, $previousDirectPermissions) as $permission) {
            AuthorizationChanged::dispatch('user.permission_assigned', auth()->id(), User::class, $this->user->id, ['permission' => $permission]);
        }

        foreach (array_diff($previousDirectPermissions, $directPermissions) as $permission) {
            AuthorizationChanged::dispatch('user.permission_removed', auth()->id(), User::class, $this->user->id, ['permission' => $permission]);
        }

        $this->loadAssignments();
        Flux::toast(variant: 'success', text: 'User access updated.');
    }

    private function loadAssignments(): void
    {
        $this->user->unsetRelation('roles')->unsetRelation('permissions');
        $this->roleNames = $this->user->getRoleNames()->sort()->values()->all();
        $this->directPermissionNames = $this->user->getDirectPermissions()->pluck('name')->sort()->values()->all();
        unset($this->roles, $this->inheritedPermissionNames, $this->effectivePermissionNames);
    }
};
?>

<section class="grid gap-6">
    <div class="flex items-center gap-3">
        <flux:button variant="ghost" icon="arrow-left" :href="route('users.index')" wire:navigate aria-label="Back to users" />
        <div class="min-w-0">
            <flux:heading size="xl">User access</flux:heading>
            <flux:subheading class="mt-1 truncate">{{ $user->name }} · {{ $user->username }}</flux:subheading>
        </div>
    </div>

    @if (auth()->user()->is($user))
        <flux:callout variant="warning" icon="exclamation-triangle" heading="Your own access is read-only here" text="Another authorized administrator must change your roles or direct permissions." />
    @endif
    @error('authorization')
        <flux:callout variant="danger" icon="exclamation-circle" heading="{{ $message }}" />
    @enderror
    @error('directPermissionNames')
        <flux:callout variant="danger" icon="exclamation-circle" heading="{{ $message }}" />
    @enderror

    <form wire:submit="save" class="grid gap-6">
        <fieldset class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <legend class="px-1 text-base font-semibold text-zinc-900 dark:text-white">Assigned roles</legend>
            <p class="mb-4 text-sm text-zinc-500">Users inherit all permissions from every selected role.</p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($this->roles as $role)
                    <label wire:key="role-choice-{{ $role->id }}" class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                        <input type="checkbox" value="{{ $role->name }}" wire:model.live="roleNames" class="size-4 rounded border-zinc-300 text-brand-700 focus:ring-brand-600 dark:border-zinc-600" @disabled(auth()->user()->is($user) || ! auth()->user()->can(Permissions::USERS_UPDATE) || ! auth()->user()->can(Permissions::PERMISSIONS_ASSIGN))>
                        <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $role->name }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <h2 class="font-semibold text-zinc-900 dark:text-white">Direct permissions</h2>
                <p class="mt-1 text-sm text-zinc-500">Use only for exceptions. Permissions already inherited from roles are marked and not duplicated.</p>
                <div class="mt-5 grid gap-5">
                    @foreach ($this->permissionGroups as $module => $permissions)
                        <fieldset wire:key="direct-module-{{ str($module)->slug() }}">
                            <legend class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">{{ $module }}</legend>
                            <div class="mt-2 grid gap-2">
                                @foreach ($permissions as $permission => $label)
                                    @php($inherited = $this->inheritedPermissionNames->contains($permission))
                                    <label wire:key="direct-{{ $permission }}" class="flex items-start gap-2 text-sm">
                                        <input type="checkbox" value="{{ $permission }}" wire:model="directPermissionNames" class="mt-0.5 size-4 rounded border-zinc-300 text-brand-700 focus:ring-brand-600 dark:border-zinc-600" @disabled($inherited || auth()->user()->is($user) || ! auth()->user()->can(Permissions::USERS_UPDATE) || ! auth()->user()->can(Permissions::PERMISSIONS_ASSIGN))>
                                        <span>
                                            <span class="text-zinc-700 dark:text-zinc-300">{{ $label }}</span>
                                            @if ($inherited)
                                                <span class="ml-1 rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-blue-700">Inherited</span>
                                            @elseif (Permissions::isPowerful($permission))
                                                <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-700">Sensitive</span>
                                            @endif
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                </div>
            </section>

            <div class="grid content-start gap-4">
                <section class="rounded-xl border border-blue-200 bg-blue-50/60 p-5">
                    <h2 class="font-semibold text-blue-950">Inherited permissions</h2>
                    <p class="mt-1 text-sm text-blue-700">Provided by the selected roles.</p>
                    <div class="mt-4 flex flex-wrap gap-1.5">
                        @forelse ($this->inheritedPermissionNames as $permission)
                            <span wire:key="inherited-{{ $permission }}" class="rounded-md border border-blue-200 bg-white px-2 py-1 text-xs text-blue-800 dark:border-blue-800 dark:bg-zinc-900 dark:text-blue-300">{{ $permission }}</span>
                        @empty
                            <span class="text-sm text-blue-700">No inherited permissions.</span>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-xl border border-emerald-200 bg-emerald-50/60 p-5">
                    <h2 class="font-semibold text-emerald-950">Effective permissions</h2>
                    <p class="mt-1 text-sm text-emerald-700">Combined access from roles and direct grants.</p>
                    <div class="mt-4 flex flex-wrap gap-1.5">
                        @forelse ($this->effectivePermissionNames as $permission)
                            <span wire:key="effective-{{ $permission }}" class="rounded-md border border-emerald-200 bg-white px-2 py-1 text-xs text-emerald-800 dark:border-emerald-800 dark:bg-zinc-900 dark:text-emerald-300">{{ $permission }}</span>
                        @empty
                            <span class="text-sm text-emerald-700">No effective permissions.</span>
                        @endforelse
                    </div>
                </section>
            </div>
        </div>

        @if (! auth()->user()->is($user) && auth()->user()->can(Permissions::USERS_UPDATE) && auth()->user()->can(Permissions::PERMISSIONS_ASSIGN))
            <div class="sticky bottom-4 flex justify-end">
                <flux:button type="submit" variant="primary">Save user access</flux:button>
            </div>
        @endif
    </form>
</section>
