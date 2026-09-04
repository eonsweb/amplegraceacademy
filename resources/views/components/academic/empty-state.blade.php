@props(['icon', 'heading', 'description'])

<div {{ $attributes->class('grid min-h-56 place-items-center rounded-xl border border-dashed border-zinc-300 bg-zinc-50/70 px-6 py-10 text-center dark:border-zinc-700 dark:bg-zinc-900/60') }}>
    <div class="max-w-md">
        <span class="mx-auto grid size-12 place-items-center rounded-full bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-200" aria-hidden="true">
            <flux:icon :name="$icon" class="size-6" />
        </span>
        <flux:heading class="mt-4">{{ $heading }}</flux:heading>
        <flux:text class="mt-1">{{ $description }}</flux:text>
    </div>
</div>
