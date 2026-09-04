<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-950 antialiased">
        <main class="flex min-h-svh items-center justify-center px-4 py-8 sm:px-6">
            <div class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white px-6 py-9 shadow-[0_22px_55px_-30px_rgba(0,0,0,0.35)] sm:px-10 sm:py-11">
                <a href="{{ route('home') }}" class="mb-7 flex justify-center" aria-label="{{ __('Ample Grace Academy home') }}" wire:navigate>
                    <img
                        src="{{ asset('images/branding/ample-grace-logo.png') }}"
                        alt="Ample Grace Academy"
                        width="140"
                        height="145"
                        class="h-28 w-auto object-contain"
                    >
                </a>

                {{ $slot }}
            </div>
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
