<?php

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('Change your password')] class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        if (! auth()->user()?->must_change_password) {
            $this->redirectRoute('dashboard');
        }
    }

    public function save(): void
    {
        $validated = $this->validate([
            'password' => $this->passwordRules(),
        ]);

        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        if (Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Choose a password different from your temporary password.',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
            'remember_token' => Str::random(60),
        ])->save();

        session()->regenerate();
        $this->reset('password', 'password_confirmation');

        session()->flash('status', 'Password changed successfully.');
        $this->redirectRoute('dashboard', navigate: true);
    }
};
?>

<div class="flex flex-col gap-7">
    <x-auth-header
        :title="__('Change your password')"
        :description="__('You are using a temporary password. Create a new password before continuing.')"
    />

    <form wire:submit="save" class="flex flex-col gap-5">
        <flux:input
            wire:model="password"
            :label="__('New password')"
            type="password"
            required
            autocomplete="new-password"
            viewable
        />

        <flux:input
            wire:model="password_confirmation"
            :label="__('Confirm new password')"
            type="password"
            required
            autocomplete="new-password"
            viewable
        />

        <flux:button
            variant="primary"
            type="submit"
            class="w-full"
            wire:loading.attr="disabled"
            wire:target="save"
        >
            <span wire:loading.remove wire:target="save">{{ __('Change password') }}</span>
            <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
        </flux:button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="text-center">
        @csrf
        <flux:button type="submit" variant="ghost">{{ __('Log out') }}</flux:button>
    </form>
</div>
