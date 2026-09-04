@props(['icon', 'label', 'value', 'trend', 'trendTone' => 'positive'])

<article {{ $attributes->class('rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900') }}>
    <div class="flex items-center gap-4">
        <span class="grid size-12 shrink-0 place-items-center rounded-full bg-brand-700 text-white" aria-hidden="true">
            <flux:icon :name="$icon" class="size-6" />
        </span>
        <div class="min-w-0">
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
            <p class="mt-1 truncate text-xl font-bold tracking-tight text-zinc-950 dark:text-white">{{ $value }}</p>
            <p @class(['mt-1 flex items-center gap-1 text-xs font-medium', 'text-emerald-600 dark:text-emerald-400' => $trendTone === 'positive', 'text-zinc-500 dark:text-zinc-400' => $trendTone === 'neutral'])>
                @if ($trendTone === 'positive')
                    <flux:icon name="arrow-up" class="size-3.5" aria-hidden="true" />
                @else
                    <flux:icon name="minus" class="size-3.5" aria-hidden="true" />
                @endif
                <span>{{ $trend }}</span>
            </p>
        </div>
    </div>
</article>
