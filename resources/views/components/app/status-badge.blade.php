@props(['status'])

<span {{ $attributes->class('inline-flex items-center gap-1.5 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700') }}>
    <span class="size-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
    {{ $status }}
</span>
