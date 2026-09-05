@props(['model', 'value' => null])

<div
    x-data="{
        dateOfBirth: @js($value),
        calculatedAge() {
            if (! this.dateOfBirth) return '—'

            const [year, month, day] = this.dateOfBirth.split('-').map(Number)
            const today = new Date()
            const birthDate = new Date(year, month - 1, day)

            if (birthDate > today) return '—'

            let age = today.getFullYear() - year

            if (today.getMonth() + 1 < month || (today.getMonth() + 1 === month && today.getDate() < day)) {
                age--
            }

            return `${age} year${age === 1 ? '' : 's'}`
        },
    }"
    x-on:input="if ($event.target.type === 'date') dateOfBirth = $event.target.value"
    {{ $attributes->class('grid gap-4 sm:grid-cols-2') }}
>
    <flux:input wire:model="{{ $model }}" type="date" label="Date of birth" />
    <div class="grid content-start gap-2">
        <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Age</span>
        <div class="flex min-h-10 items-center rounded-lg border border-zinc-200 bg-zinc-50 px-3 text-sm text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200" x-text="calculatedAge()"></div>
    </div>
</div>
