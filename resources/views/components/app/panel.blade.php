@props(['title'])

<section {{ $attributes->class('overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm') }}>
    <header class="flex min-h-15 items-center justify-between gap-4 border-b border-zinc-200 px-4 py-3">
        <h2 class="text-sm font-semibold text-zinc-950">{{ $title }}</h2>
        @isset($action)
            <div>{{ $action }}</div>
        @endisset
    </header>
    {{ $slot }}
</section>
