@props([
    'heading' => '',
    'subheading' => '',
    'contentWidth' => 'max-w-lg',
])

<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        {{ $navigation }}
    </div>

    <flux:separator class="md:hidden" />

    <div class="min-w-0 flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading }}</flux:heading>
        <flux:subheading>{{ $subheading }}</flux:subheading>

        <div @class(['mt-5 w-full', $contentWidth])>
            {{ $slot }}
        </div>
    </div>
</div>
