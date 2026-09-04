@props(['icon', 'label', 'href' => null, 'active' => false])

@php
    $classes = $active
        ? 'bg-white/15 text-white shadow-sm'
        : 'text-white/70 hover:bg-white/10 hover:text-white';
@endphp

@if ($href)
    <a href="{{ $href }}" @if ($active) aria-current="page" @endif @if (! $active) wire:navigate @endif
        {{ $attributes->class("flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white $classes") }}>
        <flux:icon :name="$icon" class="size-5 shrink-0" />
        <span>{{ $label }}</span>
    </a>
@else
    <span aria-disabled="true" title="Coming soon"
        {{ $attributes->class("flex min-h-10 cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium $classes") }}>
        <flux:icon :name="$icon" class="size-5 shrink-0" />
        <span>{{ $label }}</span>
    </span>
@endif
