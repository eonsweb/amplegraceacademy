<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body
        class="min-h-screen overflow-x-hidden bg-zinc-50 text-zinc-800 antialiased"
        x-data="{ sidebarOpen: false }"
        x-on:keydown.escape.window="if (sidebarOpen) { sidebarOpen = false; $nextTick(() => $refs.sidebarToggle?.focus()) }"
    >
        <a href="#main-content" class="fixed left-3 top-3 z-[60] -translate-y-20 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-brand-800 shadow-lg focus:translate-y-0 focus:outline-2 focus:outline-offset-2 focus:outline-brand-700">
            Skip to main content
        </a>

        <div
            x-cloak
            x-show="sidebarOpen"
            x-transition.opacity.duration.150ms
            class="fixed inset-0 z-40 bg-black/45 lg:hidden"
            aria-hidden="true"
            x-on:click="sidebarOpen = false; $nextTick(() => $refs.sidebarToggle?.focus())"
        ></div>

        <x-app.sidebar />

        <div class="min-h-screen lg:pl-72">
            <x-app.topbar />

            <main id="main-content" class="mx-auto w-full max-w-[1600px] px-4 py-5 sm:px-6 lg:px-7 lg:py-6">
                {{ $slot }}
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
