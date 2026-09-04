<?php

use App\Events\UserManagementChanged;
use App\Models\User;
use App\Support\Authorization\AuthorizationSafety;
use App\Support\Authorization\Permissions;
use App\Support\Settings\SystemSettings;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

new #[Title('Users')] class extends Component {
    use WithPagination;

    private const TEMPORARY_PASSWORD = 'password';

    #[Url]
    public string $search = '';

    #[Url]
    public string $roleFilter = '';

    #[Url]
    public string $statusFilter = '';

    public bool $showUserForm = false;

    public ?int $editingUserId = null;

    public string $name = '';

    public string $username = '';

    public string $email = '';

    /** @var list<string> */
    public array $roleNames = [];

    public bool $isActive = true;

    public int $recordsPerPage = 25;

    public function mount(SystemSettings $settings): void
    {
        Gate::authorize(Permissions::USERS_VIEW);
        $this->recordsPerPage = $settings->recordsPerPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'roleFilter', 'statusFilter');
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, User> */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return User::query()
            ->select(['id', 'name', 'username', 'email', 'is_active', 'must_change_password', 'created_at'])
            ->with('roles:id,name,guard_name')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('username', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->when($this->roleFilter !== '', function (Builder $query): void {
                $query->whereHas('roles', fn (Builder $roleQuery): Builder => $roleQuery
                    ->where('name', $this->roleFilter)
                    ->where('guard_name', 'web'));
            })
            ->when($this->statusFilter === 'active', fn (Builder $query): Builder => $query->where('is_active', true))
            ->when($this->statusFilter === 'inactive', fn (Builder $query): Builder => $query->where('is_active', false))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($this->recordsPerPage);
    }

    /** @return Collection<int, Role> */
    #[Computed]
    public function roles(): Collection
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'guard_name']);
    }

    public function openCreateUser(): void
    {
        Gate::authorize(Permissions::USERS_CREATE);

        $this->resetUserForm();
        $this->showUserForm = true;
    }

    public function editUser(int $userId): void
    {
        Gate::authorize(Permissions::USERS_UPDATE);

        $user = User::query()->with('roles:id,name,guard_name')->findOrFail($userId);

        $this->resetValidation();
        $this->editingUserId = $user->id;
        $this->name = $user->name;
        $this->username = $user->username;
        $this->email = $user->email;
        $this->roleNames = $user->roles->pluck('name')->sort()->values()->all();
        $this->isActive = $user->is_active;
        $this->showUserForm = true;
    }

    public function saveUser(AuthorizationSafety $safety): void
    {
        $this->name = trim($this->name);
        $this->username = Str::lower(trim($this->username));
        $this->email = Str::lower(trim($this->email));

        $user = $this->editingUserId === null
            ? null
            : User::query()->with(['roles:id,name,guard_name', 'permissions:id,name'])->findOrFail($this->editingUserId);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9._-]+$/', Rule::unique(User::class, 'username')->ignore($user)],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($user)],
            'roleNames' => ['required', 'array', 'min:1'],
            'roleNames.*' => [
                'string',
                'distinct',
                Rule::exists(config('permission.table_names.roles'), 'name')
                    ->where(fn (QueryBuilder $query): QueryBuilder => $query->where('guard_name', 'web')),
            ],
            'isActive' => ['boolean'],
        ], [
            'username.regex' => 'The username may only contain letters, numbers, dots, underscores, and hyphens.',
            'roleNames.required' => 'Select at least one role.',
            'roleNames.min' => 'Select at least one role.',
        ]);

        if ($user === null) {
            $this->createUser($validated, $safety);

            return;
        }

        $this->updateUser($user, $validated, $safety);
    }

    public function resetUserPassword(int $userId, AuthorizationSafety $safety): void
    {
        Gate::authorize(Permissions::USERS_RESET_PASSWORD);

        $user = User::query()->findOrFail($userId);
        $safety->ensureUserMayHavePasswordReset(auth()->user(), $user);

        DB::transaction(function () use ($user): void {
            $user->forceFill([
                'password' => Hash::make(self::TEMPORARY_PASSWORD),
                'must_change_password' => true,
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('sessions')->where('user_id', $user->id)->delete();
        });

        UserManagementChanged::dispatch('user.password_reset', auth()->id(), $user->id);

        unset($this->users);
        Flux::toast(variant: 'success', text: 'Password reset. Temporary password: password. The user must change it at their next login.');
    }

    public function toggleUserStatus(int $userId, AuthorizationSafety $safety): void
    {
        Gate::authorize(Permissions::USERS_CHANGE_STATUS);

        $user = User::query()->findOrFail($userId);

        if ($user->is_active) {
            $safety->ensureUserMayBeDeactivated(auth()->user(), $user);
        }

        DB::transaction(function () use ($user): void {
            $user->update(['is_active' => ! $user->is_active]);

            if (! $user->is_active) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        });

        UserManagementChanged::dispatch(
            $user->is_active ? 'user.activated' : 'user.deactivated',
            auth()->id(),
            $user->id,
        );

        unset($this->users);
        Flux::toast(variant: 'success', text: $user->is_active ? 'User activated.' : 'User deactivated.');
    }

    public function deleteUser(int $userId, AuthorizationSafety $safety): void
    {
        Gate::authorize(Permissions::USERS_DELETE);

        $user = User::query()->findOrFail($userId);
        $safety->ensureUserMayBeDeleted(auth()->user(), $user);

        DB::transaction(function () use ($user): void {
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->delete();
        });

        UserManagementChanged::dispatch('user.deleted', auth()->id(), $userId);

        unset($this->users);
        Flux::toast(variant: 'success', text: 'User deleted.');
    }

    public function canAssignRole(Role $role): bool
    {
        return auth()->user()->can(Permissions::USERS_ASSIGN_ROLE)
            && $role->permissions->every(fn ($permission): bool => auth()->user()->can($permission->name));
    }

    /**
     * @param  array{name: string, username: string, email: string, roleNames: list<string>, isActive: bool}  $validated
     */
    private function createUser(array $validated, AuthorizationSafety $safety): void
    {
        Gate::authorize(Permissions::USERS_CREATE);
        Gate::authorize(Permissions::USERS_ASSIGN_ROLE);
        $safety->ensureRolesAndPermissionsMayBeGranted(auth()->user(), $validated['roleNames'], []);

        $user = DB::transaction(function () use ($validated): User {
            $createdUser = User::query()->create([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => Hash::make(self::TEMPORARY_PASSWORD),
                'is_active' => $validated['isActive'],
                'must_change_password' => true,
            ]);

            $createdUser->syncRoles($validated['roleNames']);

            return $createdUser;
        });

        UserManagementChanged::dispatch('user.created', auth()->id(), $user->id, [
            'roles' => $validated['roleNames'],
            'is_active' => $validated['isActive'],
        ]);

        $this->finishSaving();
        Flux::toast(variant: 'success', text: 'User created. Temporary password: password. A password change is required at first login.');
    }

    /**
     * @param  array{name: string, username: string, email: string, roleNames: list<string>, isActive: bool}  $validated
     */
    private function updateUser(User $user, array $validated, AuthorizationSafety $safety): void
    {
        Gate::authorize(Permissions::USERS_UPDATE);

        $previousRoles = $user->roles->pluck('name')->sort()->values()->all();
        $rolesChanged = $previousRoles !== collect($validated['roleNames'])->sort()->values()->all();
        $statusChanged = $user->is_active !== $validated['isActive'];
        $changedFields = array_keys(array_filter([
            'name' => $user->name !== $validated['name'],
            'username' => $user->username !== $validated['username'],
            'email' => $user->email !== $validated['email'],
        ]));

        if ($rolesChanged) {
            Gate::authorize(Permissions::USERS_ASSIGN_ROLE);
            $safety->ensureUserMayBeManaged(auth()->user(), $user);
            $directPermissions = $user->permissions->pluck('name')->all();
            $safety->ensureRolesAndPermissionsMayBeGranted(auth()->user(), $validated['roleNames'], $directPermissions);
            $safety->ensureAdministrativeAccessRemains($user, $validated['roleNames'], $directPermissions);
        }

        if ($statusChanged) {
            Gate::authorize(Permissions::USERS_CHANGE_STATUS);

            if (! $validated['isActive']) {
                $safety->ensureUserMayBeDeactivated(auth()->user(), $user);
            }
        }

        DB::transaction(function () use ($user, $validated, $rolesChanged): void {
            $user->update([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $validated['email'],
                'is_active' => $validated['isActive'],
            ]);

            if ($rolesChanged) {
                $user->syncRoles($validated['roleNames']);
            }
        });

        if ($changedFields !== []) {
            UserManagementChanged::dispatch('user.updated', auth()->id(), $user->id, [
                'fields' => $changedFields,
            ]);
        }

        if ($rolesChanged) {
            UserManagementChanged::dispatch('user.role_changed', auth()->id(), $user->id, [
                'roles' => $validated['roleNames'],
            ]);
        }

        if ($statusChanged) {
            UserManagementChanged::dispatch(
                $user->is_active ? 'user.activated' : 'user.deactivated',
                auth()->id(),
                $user->id,
            );
        }

        $this->finishSaving();
        Flux::toast(variant: 'success', text: 'User updated.');
    }

    private function finishSaving(): void
    {
        $this->showUserForm = false;
        $this->resetUserForm();
        unset($this->users);
    }

    private function resetUserForm(): void
    {
        $this->reset('editingUserId', 'name', 'username', 'email', 'roleNames');
        $this->isActive = true;
        $this->resetValidation();
    }
};
?>

<section class="grid gap-6">
    @inject('systemSettings', 'App\Support\Settings\SystemSettings')
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
        <div>
            <flux:heading size="xl">Users</flux:heading>
            <flux:subheading class="mt-1">Create accounts and manage access, status, and credentials.</flux:subheading>
        </div>
        @if (auth()->user()->can(Permissions::USERS_CREATE) && auth()->user()->can(Permissions::USERS_ASSIGN_ROLE))
            <flux:button variant="primary" icon="plus" wire:click="openCreateUser">Add User</flux:button>
        @endif
    </div>

    @error('status')
        <flux:callout variant="danger" icon="exclamation-circle" heading="{{ $message }}" />
    @enderror
    @error('user')
        <flux:callout variant="danger" icon="exclamation-circle" heading="{{ $message }}" />
    @enderror

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="grid gap-3 border-b border-zinc-200 p-4 dark:border-zinc-800 md:grid-cols-[minmax(0,1fr)_12rem_10rem_auto]">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search name, username, or email..." aria-label="Search users" />

            <flux:select wire:model.live="roleFilter" aria-label="Filter users by role">
                <flux:select.option value="">All roles</flux:select.option>
                @foreach ($this->roles as $role)
                    <flux:select.option wire:key="role-filter-{{ $role->id }}" value="{{ $role->name }}">{{ $role->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="statusFilter" aria-label="Filter users by status">
                <flux:select.option value="">All statuses</flux:select.option>
                <flux:select.option value="active">Active</flux:select.option>
                <flux:select.option value="inactive">Inactive</flux:select.option>
            </flux:select>

            @if ($search !== '' || $roleFilter !== '' || $statusFilter !== '')
                <flux:button variant="ghost" wire:click="resetFilters">Clear</flux:button>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[820px] text-left text-sm">
                <caption class="sr-only">Application users with roles and account status</caption>
                <thead class="bg-zinc-50 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:bg-zinc-800 dark:text-zinc-300">
                    <tr>
                        <th scope="col" class="px-4 py-3">User</th>
                        <th scope="col" class="px-4 py-3">Username</th>
                        <th scope="col" class="px-4 py-3">Roles</th>
                        <th scope="col" class="px-4 py-3">Status</th>
                        <th scope="col" class="px-4 py-3">Created</th>
                        <th scope="col" class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($this->users as $user)
                        <tr wire:key="user-{{ $user->id }}">
                            <td class="px-4 py-3">
                                <p class="font-semibold text-zinc-900 dark:text-white">{{ $user->name }}</p>
                                <p class="text-xs text-zinc-500">{{ $user->email }}</p>
                            </td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $user->username }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    @forelse ($user->roles as $role)
                                        <flux:badge size="sm" wire:key="user-{{ $user->id }}-role-{{ $role->id }}">{{ $role->name }}</flux:badge>
                                    @empty
                                        <span class="text-xs text-amber-700">No role assigned</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1.5 font-medium {{ $user->is_active ? 'text-emerald-700' : 'text-zinc-500' }}">
                                    <span class="size-2 rounded-full {{ $user->is_active ? 'bg-emerald-500' : 'bg-zinc-400' }}" aria-hidden="true"></span>
                                    {{ $user->is_active ? 'Active' : 'Inactive' }}
                                </span>
                                @if ($user->must_change_password)
                                    <span class="mt-1 block text-xs text-amber-700">Password change required</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $systemSettings->formatDate($user->created_at) }}</td>
                            <td class="px-4 py-3 text-right">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="Actions for {{ $user->name }}" />
                                    <flux:menu>
                                        @can(Permissions::USERS_UPDATE)
                                            <flux:menu.item icon="pencil-square" wire:click="editUser({{ $user->id }})">Edit</flux:menu.item>
                                        @endcan
                                        <flux:menu.item icon="key" :href="route('users.authorization', $user)" wire:navigate>Manage access</flux:menu.item>
                                        @can(Permissions::USERS_RESET_PASSWORD)
                                            @if (! auth()->user()->is($user))
                                                <flux:menu.item icon="arrow-path" wire:click="resetUserPassword({{ $user->id }})" wire:confirm="Reset this user's password to the standard temporary password?">Reset password</flux:menu.item>
                                            @endif
                                        @endcan
                                        @can(Permissions::USERS_CHANGE_STATUS)
                                            @if (! auth()->user()->is($user))
                                                <flux:menu.item icon="{{ $user->is_active ? 'pause-circle' : 'play-circle' }}" wire:click="toggleUserStatus({{ $user->id }})" wire:confirm="{{ $user->is_active ? 'Deactivate this user?' : 'Activate this user?' }}">
                                                    {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                                </flux:menu.item>
                                            @endif
                                        @endcan
                                        @can(Permissions::USERS_DELETE)
                                            @if (! $user->is_active)
                                                <flux:menu.separator />
                                                <flux:menu.item variant="danger" icon="trash" wire:click="deleteUser({{ $user->id }})" wire:confirm="Permanently delete this inactive user? This cannot be undone.">Delete</flux:menu.item>
                                            @endif
                                        @endcan
                                    </flux:menu>
                                </flux:dropdown>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center">
                                <p class="font-medium text-zinc-700 dark:text-zinc-200">{{ $search !== '' || $roleFilter !== '' || $statusFilter !== '' ? 'No users match your current search or filters.' : 'No users found.' }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->users->hasPages())
            <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">{{ $this->users->links() }}</div>
        @endif
    </div>

    <flux:modal wire:model.self="showUserForm" class="max-w-2xl">
        <form wire:submit="saveUser" class="grid gap-6">
            <div>
                <flux:heading size="lg">{{ $editingUserId === null ? 'Add User' : 'Edit User' }}</flux:heading>
                <flux:subheading class="mt-1">{{ $editingUserId === null ? 'A temporary password will be shown once after creation.' : 'Passwords are managed separately from account details.' }}</flux:subheading>
            </div>

            @error('permissionNames')
                <flux:callout variant="danger" icon="exclamation-circle" heading="{{ $message }}" />
            @enderror
            @error('authorization')
                <flux:callout variant="danger" icon="exclamation-circle" heading="{{ $message }}" />
            @enderror

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" label="Full name" maxlength="255" required autofocus />
                <flux:input wire:model="username" label="Username" maxlength="50" required autocomplete="off" />
                <flux:input wire:model="email" type="email" label="Email" maxlength="255" required class="sm:col-span-2" />
            </div>

            <fieldset>
                <legend class="text-sm font-semibold text-zinc-900 dark:text-white">Roles</legend>
                <p class="mt-1 text-xs text-zinc-500">Choose one or more roles. Roles you are not allowed to grant are disabled.</p>
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    @foreach ($this->roles as $role)
                        <label wire:key="user-form-role-{{ $role->id }}" class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 text-sm has-disabled:bg-zinc-50 has-disabled:text-zinc-400 dark:border-zinc-700 dark:has-disabled:bg-zinc-800">
                            <input type="checkbox" value="{{ $role->name }}" wire:model="roleNames" class="size-4 rounded border-zinc-300 text-brand-700 focus:ring-brand-600 dark:border-zinc-600" @disabled(! $this->canAssignRole($role))>
                            <span class="font-medium">{{ $role->name }}</span>
                        </label>
                    @endforeach
                </div>
                <flux:error name="roleNames" />
            </fieldset>

            <flux:switch wire:model="isActive" label="Active account" description="Inactive users cannot sign in." :disabled="$editingUserId === auth()->id() || ! auth()->user()->can(Permissions::USERS_CHANGE_STATUS)" />

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showUserForm', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveUser">
                    <span wire:loading.remove wire:target="saveUser">{{ $editingUserId === null ? 'Create user' : 'Save changes' }}</span>
                    <span wire:loading wire:target="saveUser">Saving...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
