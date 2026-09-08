<?php

use App\Actions\Users\CreateManagedUser;
use App\Events\StaffManagementChanged;
use App\Events\UserManagementChanged;
use App\Models\Staff;
use App\Models\User;
use App\StaffStatus;
use App\Support\Authorization\AuthorizationSafety;
use App\Support\Authorization\Permissions;
use App\Support\Settings\SystemSettings;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

new #[Title('Staff Profile')] class extends Component {
    #[Locked] public int $staffId;
    public bool $showCreateAccount = false;
    public bool $showLinkAccount = false;
    public string $username = '';
    public string $userEmail = '';
    /** @var list<string> */
    public array $roleNames = [];
    public bool $userIsActive = true;
    public string $userSearch = '';
    public string $selectedUserId = '';

    public function mount(Staff $staff): void
    {
        Gate::authorize('view', $staff);
        $this->staffId = $staff->id;
    }

    #[Computed]
    public function staff(): Staff
    {
        return Staff::query()->select(['id', 'staff_number', 'first_name', 'last_name', 'gender', 'date_of_birth', 'email', 'phone', 'address', 'role_title', 'department', 'employment_type', 'employment_date', 'photo', 'status', 'created_at', 'updated_at'])
            ->with(['user' => fn ($query) => $query->select(['id', 'staff_id', 'name', 'username', 'email', 'is_active', 'must_change_password'])->with('roles:id,name,guard_name')])
            ->findOrFail($this->staffId);
    }

    /** @return Collection<int, Role> */
    #[Computed]
    public function roles(): Collection
    {
        if (! $this->showCreateAccount
            || Gate::denies('manageUserAccount', $this->staff)
            || Gate::denies(Permissions::USERS_CREATE)
            || Gate::denies(Permissions::USERS_ASSIGN_ROLE)) {
            return collect();
        }

        return Role::query()->where('guard_name', 'web')->with('permissions:id,name')->orderBy('name')->get(['id', 'name', 'guard_name']);
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function userResults(): Collection
    {
        $search = trim($this->userSearch);
        if (! $this->showLinkAccount
            || mb_strlen($search) < 2
            || Gate::denies('manageUserAccount', $this->staff)
            || Gate::denies(Permissions::USERS_UPDATE)) {
            return collect();
        }

        return User::query()->select(['id', 'name', 'username', 'email'])
            ->whereNull('staff_id')
            ->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')->orWhere('username', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%');
            })->orderBy('name')->limit(8)->get();
    }

    public function canAssignRole(Role $role): bool
    {
        return auth()->user()->can(Permissions::USERS_ASSIGN_ROLE)
            && $role->permissions->every(fn ($permission): bool => auth()->user()->can($permission->name));
    }

    public function openCreateAccount(): void
    {
        Gate::authorize('manageUserAccount', $this->staff);
        Gate::authorize(Permissions::USERS_CREATE);
        Gate::authorize(Permissions::USERS_ASSIGN_ROLE);
        abort_if($this->staff->user !== null, 422);
        $this->resetValidation();
        $this->username = Str::of($this->staff->first_name.'.'.$this->staff->last_name)->lower()->replaceMatches('/[^a-z0-9._-]+/', '.')->trim('.')->limit(50, '')->toString();
        $this->userEmail = $this->staff->email ?? '';
        $this->roleNames = [];
        $this->userIsActive = true;
        $this->showCreateAccount = true;
    }

    public function createAccount(CreateManagedUser $creator, AuthorizationSafety $safety): void
    {
        $staff = Staff::query()->whereDoesntHave('user')->findOrFail($this->staffId);
        Gate::authorize('manageUserAccount', $staff);
        Gate::authorize(Permissions::USERS_CREATE);
        Gate::authorize(Permissions::USERS_ASSIGN_ROLE);
        $this->username = Str::lower(trim($this->username));
        $this->userEmail = Str::lower(trim($this->userEmail));
        $validated = $this->validate([
            'username' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9._-]+$/', Rule::unique(User::class, 'username')],
            'userEmail' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'roleNames' => ['required', 'array', 'min:1'],
            'roleNames.*' => ['string', 'distinct', Rule::exists(config('permission.table_names.roles'), 'name')->where(fn (QueryBuilder $query): QueryBuilder => $query->where('guard_name', 'web'))],
            'userIsActive' => ['boolean'],
        ], ['username.regex' => 'The username may only contain letters, numbers, dots, underscores, and hyphens.', 'roleNames.required' => 'Select at least one role.', 'roleNames.min' => 'Select at least one role.']);
        $actor = auth()->user();
        abort_if($actor === null, 403);

        try {
            $creator->handle($actor, ['name' => $staff->fullName(), 'username' => $validated['username'], 'email' => $validated['userEmail'], 'is_active' => $validated['userIsActive']], $validated['roleNames'], $safety, $staff);
        } catch (UniqueConstraintViolationException) {
            $this->addError('account', 'This staff member is already linked to an account.');
            return;
        }

        StaffManagementChanged::dispatch('staff.user_linked', $actor->id, $staff->id);
        $this->showCreateAccount = false;
        unset($this->staff, $this->roles);
        Flux::toast(variant: 'success', text: 'User account created. Temporary password: password. A password change is required at first login.');
    }

    public function openLinkAccount(): void
    {
        Gate::authorize('manageUserAccount', $this->staff);
        Gate::authorize(Permissions::USERS_UPDATE);
        abort_if($this->staff->user !== null, 422);
        $this->reset('userSearch', 'selectedUserId');
        $this->resetValidation();
        $this->showLinkAccount = true;
    }

    public function selectUser(int $userId): void
    {
        Gate::authorize('manageUserAccount', $this->staff);
        Gate::authorize(Permissions::USERS_UPDATE);
        $user = User::query()->select(['id', 'name', 'username', 'email'])->whereNull('staff_id')->findOrFail($userId);
        $this->selectedUserId = (string) $user->id;
        $this->userSearch = $user->name.' · '.$user->username;
    }

    public function linkAccount(): void
    {
        $staff = Staff::query()->whereDoesntHave('user')->findOrFail($this->staffId);
        Gate::authorize('manageUserAccount', $staff);
        Gate::authorize(Permissions::USERS_UPDATE);
        $validated = $this->validate(['selectedUserId' => ['required', 'integer', Rule::exists(User::class, 'id')->where(fn (QueryBuilder $query): QueryBuilder => $query->whereNull('staff_id'))]]);
        $user = User::query()->whereNull('staff_id')->findOrFail($validated['selectedUserId']);
        try {
            $user->update(['staff_id' => $staff->id]);
        } catch (UniqueConstraintViolationException) {
            $this->addError('selectedUserId', 'The staff member or user account was linked by another request. Refresh and try again.');

            return;
        }
        $actorId = (int) auth()->id();
        StaffManagementChanged::dispatch('staff.user_linked', $actorId, $staff->id, ['user_id' => $user->id]);
        UserManagementChanged::dispatch('user.staff_linked', $actorId, $user->id, ['staff_id' => $staff->id]);
        $this->showLinkAccount = false;
        unset($this->staff, $this->userResults);
        Flux::toast(variant: 'success', text: 'Existing user account linked.');
    }

    public function unlinkAccount(): void
    {
        $staff = Staff::query()->findOrFail($this->staffId);
        Gate::authorize('manageUserAccount', $staff);
        Gate::authorize(Permissions::USERS_UPDATE);
        $user = User::query()->where('staff_id', $staff->id)->firstOrFail();
        $user->update(['staff_id' => null]);
        $actorId = (int) auth()->id();
        StaffManagementChanged::dispatch('staff.user_unlinked', $actorId, $staff->id, ['user_id' => $user->id]);
        UserManagementChanged::dispatch('user.staff_unlinked', $actorId, $user->id, ['staff_id' => $staff->id]);
        unset($this->staff);
        Flux::toast(variant: 'success', text: 'User account unlinked. The account was not deleted or deactivated.');
    }

    public function toggleStatus(): void
    {
        $staff = Staff::query()->findOrFail($this->staffId);
        Gate::authorize('manageStatus', $staff);
        $staff->update(['status' => $staff->status === StaffStatus::Active ? StaffStatus::Inactive : StaffStatus::Active]);
        StaffManagementChanged::dispatch($staff->status === StaffStatus::Active ? 'staff.activated' : 'staff.deactivated', (int) auth()->id(), $staff->id);
        unset($this->staff);
        Flux::toast(variant: 'success', text: $staff->status === StaffStatus::Active ? 'Staff member activated.' : 'Staff member deactivated.');
    }

    public function delete(): void
    {
        $staff = Staff::query()->findOrFail($this->staffId);
        Gate::authorize('delete', $staff);
        if ($staff->status !== StaffStatus::Inactive) { $this->addError('delete', 'Deactivate this staff member before deleting the record.'); return; }
        if ($staff->user()->exists()) { $this->addError('delete', 'Unlink the user account before deleting this staff member.'); return; }
        $photo = $staff->photo;

        try {
            $staff->delete();
        } catch (QueryException) {
            $this->addError('delete', 'This staff member is referenced by historical records and cannot be deleted. Mark the record inactive instead.');
            return;
        }

        if ($photo) { Storage::disk('public')->delete($photo); }
        StaffManagementChanged::dispatch('staff.deleted', (int) auth()->id(), $this->staffId);
        Flux::toast(variant: 'success', text: 'Staff record deleted.');
        $this->redirectRoute('staff.index', navigate: true);
    }
};
?>

@inject('systemSettings', 'App\Support\Settings\SystemSettings')
<div class="grid gap-5">
    @if(session('staff-created'))<flux:callout variant="success" icon="check-circle" heading="Staff member created successfully" text="{{ session('staff-created') }}" />@endif
    @error('delete')<flux:callout variant="danger" icon="exclamation-triangle" :text="$message" />@enderror
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><flux:heading size="xl">Staff Profile</flux:heading><flux:text class="mt-1">{{ $this->staff->staff_number }}</flux:text></div><div class="flex flex-wrap gap-2"><flux:button :href="route('staff.index')" wire:navigate variant="ghost">Back</flux:button>@can('update', $this->staff)<flux:button :href="route('staff.edit', $this->staff)" wire:navigate variant="primary" icon="pencil-square">Edit profile</flux:button>@endcan @can('manageStatus', $this->staff)<flux:button variant="ghost" wire:click="toggleStatus" wire:confirm="{{ $this->staff->status === StaffStatus::Active ? 'Mark this staff member inactive? Their user account will remain unchanged.' : 'Reactivate this staff member?' }}">{{ $this->staff->status === StaffStatus::Active ? 'Deactivate' : 'Activate' }}</flux:button>@endcan @can('delete', $this->staff)<flux:button variant="danger" wire:click="delete" wire:confirm="Permanently delete this inactive, unlinked staff record?">Delete</flux:button>@endcan</div></div>
    <div class="grid gap-5 xl:grid-cols-3">
        <x-app.panel title="Personal details" class="xl:col-span-2"><div class="grid gap-5 p-5 sm:grid-cols-[auto_1fr]">@if($this->staff->photoUrl())<img src="{{ $this->staff->photoUrl() }}" alt="{{ $this->staff->fullName() }}" class="size-32 rounded-xl object-cover">@else<div class="grid size-32 place-items-center rounded-xl bg-zinc-100 text-3xl font-semibold text-zinc-500 dark:bg-zinc-800">{{ str($this->staff->first_name)->substr(0, 1) }}{{ str($this->staff->last_name)->substr(0, 1) }}</div>@endif<div class="grid gap-4 sm:grid-cols-2"><div><p class="text-xs uppercase text-zinc-500">Full name</p><p class="font-semibold">{{ $this->staff->fullName() }}</p></div><div><p class="text-xs uppercase text-zinc-500">Gender</p><p>{{ $this->staff->gender?->label() ?? '—' }}</p></div><div><p class="text-xs uppercase text-zinc-500">Date of birth</p><p>{{ $this->staff->date_of_birth?->format('j M Y') ?? '—' }}</p></div><div><p class="text-xs uppercase text-zinc-500">Phone</p><p>{{ $this->staff->phone ?? '—' }}</p></div><div><p class="text-xs uppercase text-zinc-500">Email</p><p class="break-all">{{ $this->staff->email ?? '—' }}</p></div><div class="sm:col-span-2"><p class="text-xs uppercase text-zinc-500">Address</p><p>{{ $this->staff->address ?? '—' }}</p></div></div></div></x-app.panel>
        <x-app.panel title="Employment"><dl class="grid gap-4 p-5"><div><dt class="text-xs uppercase text-zinc-500">Position</dt><dd class="font-semibold">{{ $this->staff->role_title }}</dd></div><div><dt class="text-xs uppercase text-zinc-500">Department</dt><dd>{{ $this->staff->department ?? '—' }}</dd></div><div><dt class="text-xs uppercase text-zinc-500">Employment type</dt><dd>{{ $this->staff->employment_type?->label() ?? '—' }}</dd></div><div><dt class="text-xs uppercase text-zinc-500">Employment date</dt><dd>{{ $this->staff->employment_date?->format('j M Y') ?? '—' }}</dd></div><div><dt class="text-xs uppercase text-zinc-500">Status</dt><dd><flux:badge :color="$this->staff->status === StaffStatus::Active ? 'green' : 'zinc'">{{ $this->staff->status->label() }}</flux:badge></dd></div></dl></x-app.panel>
    </div>
    <x-app.panel title="Application access">
        <x-slot:action>
            @can('manageUserAccount', $this->staff)
                @if($this->staff->user === null)
                    <div class="flex flex-wrap gap-2">
                        @if(auth()->user()->can(Permissions::USERS_CREATE) && auth()->user()->can(Permissions::USERS_ASSIGN_ROLE))
                            <flux:button size="sm" variant="primary" wire:click="openCreateAccount">Create User Account</flux:button>
                        @endif
                        @can(Permissions::USERS_UPDATE)
                            <flux:button size="sm" variant="ghost" wire:click="openLinkAccount">Link Existing User</flux:button>
                        @endcan
                    </div>
                @else
                    @can(Permissions::USERS_UPDATE)
                        <flux:button size="sm" variant="ghost" wire:click="unlinkAccount" wire:confirm="Unlink this user account? The account will remain active and will not be deleted.">Unlink Account</flux:button>
                    @endcan
                @endif
            @endcan
        </x-slot:action>
        <div class="p-5">@if($this->staff->user)<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><div><p class="text-xs uppercase text-zinc-500">Account</p><p class="font-semibold">{{ $this->staff->user->name }}</p><p class="text-xs text-zinc-500">{{ $this->staff->user->username }}</p></div><div><p class="text-xs uppercase text-zinc-500">Email</p><p class="break-all">{{ $this->staff->user->email }}</p></div><div><p class="text-xs uppercase text-zinc-500">Account status</p><flux:badge :color="$this->staff->user->is_active ? 'green' : 'zinc'">{{ $this->staff->user->is_active ? 'Active' : 'Inactive' }}</flux:badge>@if($this->staff->user->must_change_password)<p class="mt-1 text-xs text-amber-700">Password change required</p>@endif</div><div><p class="text-xs uppercase text-zinc-500">Application roles</p><div class="mt-1 flex flex-wrap gap-1">@forelse($this->staff->user->roles as $role)<flux:badge size="sm" wire:key="staff-user-role-{{ $role->id }}">{{ $role->name }}</flux:badge>@empty<span>None</span>@endforelse</div></div></div>@can(Permissions::USERS_VIEW)<div class="mt-4"><flux:button size="sm" variant="ghost" :href="route('users.authorization', $this->staff->user)" wire:navigate>Manage User Access</flux:button></div>@endcan @else<p class="text-zinc-500">No application account is linked. Staff employment records do not require login access.</p>@endif</div>
    </x-app.panel>
    <x-app.panel title="Record information"><dl class="grid gap-4 p-5 sm:grid-cols-2"><div><dt class="text-xs uppercase text-zinc-500">Created</dt><dd>{{ $systemSettings->formatDate($this->staff->created_at) }}</dd></div><div><dt class="text-xs uppercase text-zinc-500">Last updated</dt><dd>{{ $systemSettings->formatDate($this->staff->updated_at) }}</dd></div></dl></x-app.panel>

    <flux:modal wire:model.self="showCreateAccount" class="max-w-2xl"><form wire:submit="createAccount" class="grid gap-5"><div><flux:heading size="lg">Create User Account</flux:heading><flux:text class="mt-1">Create login access for {{ $this->staff->fullName() }}. Employment position does not determine application roles.</flux:text></div>@error('account')<flux:callout variant="danger" :text="$message" />@enderror<div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="username" label="Username" required /><flux:input wire:model="userEmail" type="email" label="Account email" required /></div><fieldset><legend class="text-sm font-semibold">Application roles</legend><div class="mt-3 grid gap-2 sm:grid-cols-2">@foreach($this->roles as $role)<label wire:key="staff-account-role-{{ $role->id }}" class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 text-sm has-disabled:bg-zinc-50 has-disabled:text-zinc-400 dark:border-zinc-700 dark:has-disabled:bg-zinc-800"><input type="checkbox" value="{{ $role->name }}" wire:model="roleNames" class="size-4 rounded border-zinc-300" @disabled(! $this->canAssignRole($role))><span>{{ $role->name }}</span></label>@endforeach</div><flux:error name="roleNames" /></fieldset><flux:switch wire:model="userIsActive" label="Active account" description="Inactive users cannot sign in." /><div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="createAccount">Create Account</flux:button></div></form></flux:modal>
    <flux:modal wire:model.self="showLinkAccount" class="max-w-xl"><form wire:submit="linkAccount" class="grid gap-5"><div><flux:heading size="lg">Link Existing User</flux:heading><flux:text class="mt-1">Only accounts not already linked to another staff member are shown.</flux:text></div><flux:input wire:model.live.debounce.400ms="userSearch" label="Find user" icon="magnifying-glass" placeholder="Name, username, or email" />@if($this->userResults->isNotEmpty())<div class="grid max-h-56 gap-1 overflow-y-auto rounded-lg border border-zinc-200 p-2 dark:border-zinc-700">@foreach($this->userResults as $user)<button type="button" wire:key="user-result-{{ $user->id }}" wire:click="selectUser({{ $user->id }})" class="rounded-md p-2 text-left hover:bg-zinc-100 dark:hover:bg-zinc-800"><span class="font-medium">{{ $user->name }}</span><span class="block text-xs text-zinc-500">{{ $user->username }} · {{ $user->email }}</span></button>@endforeach</div>@endif @error('selectedUserId')<p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror<div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="linkAccount">Link Account</flux:button></div></form></flux:modal>
</div>
