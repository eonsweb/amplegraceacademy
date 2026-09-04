@props(['month', 'day', 'title', 'date', 'time', 'tone' => 'green'])

<article {{ $attributes->class('flex gap-3 py-3 first:pt-0 last:pb-0') }}>
    <time class="grid size-12 shrink-0 place-items-center rounded-lg bg-brand-50 text-center text-brand-800 dark:bg-brand-950 dark:text-brand-200" aria-label="{{ $month }} {{ $day }}">
        <span class="self-end text-[10px] font-bold uppercase leading-none">{{ $month }}</span>
        <span class="self-start text-xl font-bold leading-none">{{ $day }}</span>
    </time>
    <div class="min-w-0">
        <h3 class="text-sm font-semibold text-zinc-950 dark:text-white">{{ $title }}</h3>
        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $date }}</p>
        <p class="mt-1 flex items-center gap-1.5 text-[11px] text-zinc-600 dark:text-zinc-300">
            <span @class(['size-1.5 rounded-full', 'bg-emerald-500' => $tone === 'green', 'bg-amber-500' => $tone === 'amber', 'bg-brand-700' => $tone === 'brand']) aria-hidden="true"></span>
            {{ $time }}
        </p>
    </div>
</article>
