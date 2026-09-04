@props(['icon', 'title', 'description', 'date'])

<article {{ $attributes->class('flex gap-3 py-4 first:pt-0 last:pb-0') }}>
    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-700" aria-hidden="true">
        <flux:icon :name="$icon" class="size-5" />
    </span>
    <div class="min-w-0">
        <h3 class="text-sm font-semibold text-zinc-950">{{ $title }}</h3>
        <p class="mt-1 text-xs leading-5 text-zinc-600">{{ $description }}</p>
        <time class="mt-1 block text-[11px] text-zinc-500">{{ $date }}</time>
    </div>
</article>
