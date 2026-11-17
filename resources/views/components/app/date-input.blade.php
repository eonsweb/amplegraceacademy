@props(['label', 'value' => '', 'min' => '', 'max' => '', 'required' => false, 'disabled' => false, 'readonly' => false, 'description' => null, 'validateActions' => ''])

@php
    $model = $attributes->wire('model')->value();
    $live = $attributes->wire('model')->hasModifier('live');
@endphp

@assets
<script>
    window.schoolDateInput = (config) => ({
        year: '', month: '', day: '', current: '', synced: undefined, invalidValue: false,
        firstYear: 0, lastYear: 0,
        draft: { year: '', month: '', day: '' }, open: '', active: -1, showErrors: false,
        selection: null,
        stopValidation: null,
        get minimumYear() { return Number(config.min?.slice(0, 4)) || 1 },
        get maximumYear() { return Number(config.max?.slice(0, 4)) || 9999 },
        init() {
            const today = new Date().getFullYear()
            this.firstYear = Math.max(this.minimumYear, Math.min(today - 100, this.maximumYear))
            this.lastYear = Math.min(this.maximumYear, Math.max(today + 10, this.minimumYear))
            this.receive(config.model ? this.$wire.$get(config.model) : config.value)
            if (config.model) {
                this.$watch(() => this.$wire.$get(config.model), value => this.receive(value))
                const submit = this.$el.closest('form')?.getAttribute('wire:submit')?.split('(')[0]
                const actions = [...(config.validateActions || '').split(','), submit].filter(Boolean)
                this.stopValidation = this.$wire.intercept(({ action }) => {
                    if (actions.includes(action.name) && !this.confirmDate()) action.cancel()
                })
            }
        },
        receive(value) {
            value = value ?? ''
            if (value === this.synced) return
            this.synced = value
            this.current = value
            const parts = String(value).replace(/^incomplete:/, '').match(/^(\d{4})?-(\d{2})?-(\d{2})?$/)
            this.invalidValue = value !== '' && !parts
            this.year = parts?.[1] ?? ''
            this.month = parts?.[2] ?? ''
            this.day = parts?.[3] ?? ''
            if (this.year && (Number(this.year) < this.firstYear || Number(this.year) > this.lastYear)) {
                this.firstYear = Math.min(Number(this.year), Math.max(this.minimumYear, Number(this.year) - 60))
                this.lastYear = Math.max(Number(this.year), Math.min(this.maximumYear, Number(this.year) + 60))
            }
            this.formatDraft()
            this.$nextTick(() => this.validate())
        },
        get years() {
            return Array.from({ length: Math.max(0, this.lastYear - this.firstYear + 1) }, (_, index) => String(this.lastYear - index).padStart(4, '0'))
        },
        daysInMonth(year, month) {
            if (!month) return 31
            if (Number(month) === 2) {
                return !year || (year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0)) ? 29 : 28
            }
            return [4, 6, 9, 11].includes(Number(month)) ? 30 : 31
        },
        withinBounds(first, last) {
            return (!config.min || last >= config.min) && (!config.max || first <= config.max)
        },
        monthAllowed(month) {
            if (!this.year) return true
            return this.withinBounds(`${this.year}-${month}-01`, `${this.year}-${month}-${this.daysInMonth(this.year, month)}`)
        },
        get days() {
            return Array.from({ length: this.daysInMonth(this.year, this.month) }, (_, index) => String(index + 1).padStart(2, '0'))
                .filter(day => !this.year || !this.month || this.withinBounds(`${this.year}-${this.month}-${day}`, `${this.year}-${this.month}-${day}`))
        },
        selectYear(event) {
            const value = event.target.value
            if (value === 'earlier' || value === 'later') {
                if (value === 'earlier') this.firstYear = Math.max(this.minimumYear, this.firstYear - 100)
                else this.lastYear = Math.min(this.maximumYear, this.lastYear + 100)
                this.$nextTick(() => { event.target.value = this.year })
                return
            }
            this.year = value
            this.change()
        },
        destroy() { this.stopValidation?.() },
        formatDraft(part = null) {
            for (const field of part ? [part] : ['year', 'month', 'day']) {
                this.draft[field] = this[field]
            }
        },
        parse(part, value) {
            const text = value.trim()
            if (!text) return ''
            if (part === 'year') return /^\d{4}$/.test(text) && Number(text) >= this.minimumYear && Number(text) <= this.maximumYear ? text : null
            if (part === 'month') return /^\d{1,2}$/.test(text) && Number(text) >= 1 && Number(text) <= 12 ? text.padStart(2, '0') : null
            return /^\d{1,2}$/.test(text) && Number(text) >= 1 && Number(text) <= 31 ? text.padStart(2, '0') : null
        },
        numericDraft(part, value) {
            return /^[0-9]*$/.test(value) && value.length <= (part === 'year' ? 4 : 2)
        },
        beforeInput(part, event, text = event.data) {
            const target = event.target
            this.selection = { part, start: target.selectionStart, end: target.selectionEnd }
            if (text == null || (event.inputType && !event.inputType.startsWith('insert'))) return
            const next = target.value.slice(0, target.selectionStart) + text + target.value.slice(target.selectionEnd)
            if (!this.numericDraft(part, next)) event.preventDefault()
        },
        type(part, value, target = null) {
            if (config.disabled || config.readonly) return
            if (!this.numericDraft(part, value)) {
                if (target) {
                    const position = this.selection?.part === part ? this.selection : { start: Math.max(0, target.selectionStart - 1), end: Math.max(0, target.selectionStart - 1) }
                    target.value = this.draft[part]
                    target.setSelectionRange(position.start, position.end)
                }
                return
            }
            this.draft[part] = value
            this.active = -1
            this.showErrors = false
            this.validate()
        },
        commit(part) {
            if (config.disabled || config.readonly) return
            this.open = ''
            this.showErrors = true
            const value = this.parse(part, this.draft[part])
            if (value === null) { this.validate(); return }
            this.invalidValue = false
            const changed = this[part] !== value
            this[part] = value
            this.formatDraft(part)
            if (changed && (part === 'year' || part === 'month') && this.day && !this.days.includes(this.day)) {
                this.day = ''
                this.draft.day = ''
            }
            if (part === 'year' && value && (Number(value) < this.firstYear || Number(value) > this.lastYear)) {
                this.firstYear = Math.max(this.minimumYear, Number(value) - 60)
                this.lastYear = Math.min(this.maximumYear, Number(value) + 60)
            }
            this.publish()
        },
        change() {
            if (config.disabled || config.readonly) return
            this.invalidValue = false
            if (this.month && !this.monthAllowed(this.month)) this.month = ''
            if (this.day && !this.days.includes(this.day)) this.day = ''
            this.formatDraft()
            this.publish()
        },
        publish() {
            const complete = this.year && this.month && this.day
            const empty = !this.draft.year && !this.draft.month && !this.draft.day
            this.current = complete ? this.year + '-' + this.month + '-' + this.day : ''
            const committed = ['year', 'month', 'day'].every(part => this.parse(part, this.draft[part]) === this[part])
            if (committed && !this.message && (complete || empty) && this.current !== this.synced) {
                this.synced = this.current
                if (config.model) this.$wire.$set(config.model, this.current, Boolean(config.live))
                this.$dispatch('date-changed', { value: this.current })
            }
            this.$nextTick(() => this.validate())
        },
        clear() {
            if (config.disabled || config.readonly) return
            this.year = this.month = this.day = ''
            this.open = ''
            this.change()
        },
        get message() {
            if (this.invalidValue) return 'Enter a valid year, month and day.'
            const year = this.parse('year', this.draft.year)
            const month = this.parse('month', this.draft.month)
            const day = this.parse('day', this.draft.day)
            if (year === null) return 'Enter a four-digit year within the allowed range.'
            if (month === null) return 'Enter a month number from 1 to 12.'
            if (day === null) return 'Enter a valid day from 1 to 31.'
            if (day && Number(day) > this.daysInMonth(year, month)) return 'This day does not exist in the selected month and year.'
            if ((year || month || day) && !(year && month && day)) return 'Enter a year, month and day to complete this date.'
            if (year && month && day && !this.withinBounds(year + '-' + month + '-' + day, year + '-' + month + '-' + day)) return 'Enter a date within the allowed range.'
            if (config.required && !year && !month && !day) return 'Enter a year, month and day.'
            return ''
        },
        validate() { this.$refs.year.setCustomValidity(this.message) },
        confirmDate() {
            for (const part of ['year', 'month', 'day']) this.commit(part)
            this.validate()
            if (this.message) { this.$refs.year.reportValidity(); return false }
            return true
        },
        options(part) {
            if (part === 'year') return [
                ...(this.lastYear < this.maximumYear ? [{ value: 'later', label: 'Later years...' }] : []),
                ...this.years.filter(year => Number(year) >= this.minimumYear && Number(year) <= this.maximumYear).map(year => ({ value: year, label: year })),
                ...(this.firstYear > this.minimumYear ? [{ value: 'earlier', label: 'Earlier years...' }] : []),
            ]
            if (part === 'month') return Array.from({ length: 12 }, (_, index) => { const value = String(index + 1).padStart(2, '0'); return { value, label: value } }).filter(option => this.monthAllowed(option.value))
            return this.days.map(day => ({ value: day, label: day }))
        },
        toggle(part) {
            if (config.disabled || config.readonly) return
            this.open = this.open === part ? '' : part
            this.active = this.options(part).findIndex(option => option.value === this[part])
            this.$refs[part].focus()
            this.scrollOption(part)
        },
        move(part, direction) {
            if (config.disabled || config.readonly) return
            if (this.open !== part) { this.open = part; this.active = direction > 0 ? -1 : 0 }
            const length = this.options(part).length
            if (!length) { this.active = -1; return }
            this.active = (this.active + direction + length) % length
            this.scrollOption(part)
        },
        scrollOption(part) {
            this.$nextTick(() => this.$el.querySelector('[data-date-option="' + part + '-' + this.active + '"]')?.scrollIntoView({ block: 'nearest' }))
        },
        popup(part, opened) {
            const list = this.$refs[part + 'List']
            if (opened !== part) {
                if (list.matches(':popover-open')) list.hidePopover()
                return
            }
            if (!list.matches(':popover-open')) list.showPopover()
            const rect = this.$refs[part].getBoundingClientRect()
            const height = Math.min(240, list.scrollHeight)
            const below = window.innerHeight - rect.bottom - 8
            const above = below < height && rect.top > below
            Object.assign(list.style, {
                width: Math.max(128, rect.width) + 'px',
                left: Math.max(8, Math.min(rect.left, window.innerWidth - Math.max(128, rect.width) - 8)) + 'px',
                top: (above ? Math.max(8, rect.top - height - 4) : rect.bottom + 4) + 'px',
                maxHeight: Math.max(40, Math.min(240, above ? rect.top - 8 : below)) + 'px',
            })
        },
        choose(part, value) {
            if (part === 'year' && (value === 'earlier' || value === 'later')) {
                this.selectYear({ target: { value } })
                this.active = -1
                return
            }
            this.draft[part] = value
            this.commit(part)
        },
        enter(part, event) {
            if (this.open === part && this.active >= 0) {
                event.preventDefault()
                this.choose(part, this.options(part)[this.active].value)
            } else {
                if (this.open === part) event.preventDefault()
                this.commit(part)
            }
        },
    })
</script>
@endassets

<fieldset
    {{ $attributes->whereDoesntStartWith('wire:model')->class('min-w-0') }}
    x-data="schoolDateInput({ model: @js($model), live: @js($live), value: @js($value), min: @js($min), max: @js($max), disabled: @js((bool) $disabled), readonly: @js((bool) $readonly), required: @js((bool) $required), validateActions: @js($validateActions) })"
    x-id="['date-year', 'date-month', 'date-day', 'date-help', 'date-year-list', 'date-month-list', 'date-day-list']"
    x-on:resize.window="open = ''" x-on:scroll.window="open = ''"
    @disabled($disabled || $readonly)
    @if ($readonly) aria-readonly="true" @endif
>
    <legend class="mb-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ $label }}</legend>
    <div class="flex flex-wrap gap-2">
        @foreach (['year' => 'Year', 'month' => 'Month', 'day' => 'Day'] as $part => $partLabel)
            <div @class(['relative flex-1', 'min-w-24' => $part === 'year', 'min-w-32' => $part === 'month', 'min-w-20' => $part === 'day']) x-on:click.outside="if (open === '{{ $part }}') open = ''">
                <label :for="$id('date-{{ $part }}')" class="sr-only">{{ $label }}: {{ $partLabel }}</label>
                <flux:input
                    x-ref="{{ $part }}" ::id="$id('date-{{ $part }}')" type="text"
                    :placeholder="$partLabel" inputmode="numeric" pattern="[0-9]*" :maxlength="$part === 'year' ? 4 : 2" autocomplete="off"
                    x-bind:value="draft.{{ $part }}" x-on:input="type('{{ $part }}', $event.target.value, $event.target)"
                    x-on:beforeinput="beforeInput('{{ $part }}', $event)"
                    x-on:paste="beforeInput('{{ $part }}', $event, $event.clipboardData.getData('text'))"
                    x-on:drop="beforeInput('{{ $part }}', $event, $event.dataTransfer.getData('text'))"
                    x-on:blur="commit('{{ $part }}')" x-on:keydown.arrow-down.prevent="move('{{ $part }}', 1)"
                    x-on:keydown.arrow-up.prevent="move('{{ $part }}', -1)" x-on:keydown.enter="enter('{{ $part }}', $event)"
                    x-on:keydown.escape.prevent.stop="open = ''" x-on:keydown.tab="commit('{{ $part }}')"
                    role="combobox" aria-autocomplete="none" aria-haspopup="listbox"
                    ::aria-expanded="open === '{{ $part }}'" ::aria-controls="$id('date-{{ $part }}-list')"
                    ::aria-activedescendant="open === '{{ $part }}' && active >= 0 ? $id('date-{{ $part }}-list') + '-' + active : null"
                    ::aria-describedby="$id('date-help')" :required="$required" :disabled="$disabled" :readonly="$readonly"
                    :invalid="$model && $errors->has($model)" class="[&_input]:pe-10"
                    ::aria-invalid="(showErrors && Boolean(message)) || @js((bool) ($model && $errors->has($model)))"
                    x-bind:class="{ 'border-red-500': showErrors && message }"
                />
                <button type="button" tabindex="-1" aria-label="Show {{ strtolower($partLabel) }} options" x-on:pointerdown.prevent x-on:click="toggle('{{ $part }}')" @disabled($disabled || $readonly) class="absolute inset-y-0 end-0 flex w-9 items-center justify-center text-zinc-400 disabled:opacity-50">
                    <flux:icon.chevron-down variant="mini" class="size-4" />
                </button>
                <ul x-cloak popover="manual" x-ref="{{ $part }}List" x-effect="popup('{{ $part }}', open)" :id="$id('date-{{ $part }}-list')" role="listbox" aria-label="{{ $label }}: {{ $partLabel }} options" class="fixed end-auto bottom-auto m-0 max-h-60 min-w-32 overflow-y-auto rounded-lg border border-zinc-200 bg-white p-1 text-sm shadow-lg dark:border-white/10 dark:bg-zinc-800">
                    <template x-for="(option, index) in options('{{ $part }}')" :key="option.value">
                        <li role="option" :id="$id('date-{{ $part }}-list') + '-' + index" :data-date-option="'{{ $part }}-' + index" :aria-selected="option.value === {{ $part }}" x-on:pointerdown.prevent x-on:click="choose('{{ $part }}', option.value)" x-text="option.label" :class="active === index ? 'bg-zinc-100 dark:bg-zinc-700' : ''" class="cursor-pointer rounded px-2 py-1.5 text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-700"></li>
                    </template>
                </ul>
            </div>
        @endforeach
    </div>
    <div :id="$id('date-help')">
        @if ($description)<flux:description class="mt-2">{{ $description }}</flux:description>@endif
        <p x-cloak x-show="showErrors && message" x-text="message" role="status" class="mt-2 text-sm text-red-600 dark:text-red-400"></p>
        @if ($model)<flux:error :name="$model" />@endif
    </div>
    @unless ($required || $disabled || $readonly)
        <button type="button" x-cloak x-show="draft.year || draft.month || draft.day || invalidValue" x-on:click="clear()" class="mt-1 text-xs text-zinc-500 underline hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white" aria-label="Clear {{ $label }}">Clear date</button>
    @endunless
</fieldset>
