<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-7">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your username and password below to log in')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form
            method="POST"
            action="{{ route('login.store') }}"
            class="flex flex-col gap-5"
            x-data="{ submitting: false }"
            x-on:submit="submitting = true"
        >
            @csrf

            <flux:input
                name="username"
                :label="__('Username')"
                :value="old('username')"
                type="text"
                icon="user"
                required
                autofocus
                autocomplete="username"
                :placeholder="__('Enter your username')"
            />

            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                icon="lock-closed"
                required
                autocomplete="current-password"
                :placeholder="__('Enter your password')"
                viewable
            />

            <div class="flex items-center justify-between gap-4">
                <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

                @if (Route::has('password.request'))
                    <flux:link class="text-sm text-[#8a0824] hover:text-[#67061b]" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </flux:link>
                @endif
            </div>

            <flux:button
                variant="primary"
                type="submit"
                class="w-full !bg-[#8a0824] !text-white hover:!bg-[#76071f] active:!bg-[#65051a] focus-visible:!ring-[#8a0824] disabled:cursor-not-allowed disabled:opacity-70"
                data-test="login-button"
                x-bind:disabled="submitting"
                x-bind:aria-busy="submitting"
            >
                <span x-show="!submitting">{{ __('Log in') }}</span>
                <span x-cloak x-show="submitting">{{ __('Logging in...') }}</span>
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
