<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
    <flux:select label="Academic year" wire:model.live="academicYearId">
        <option value="">All academic years</option>
        @foreach ($this->years as $year)<option wire:key="filter-year-{{ $year->id }}" value="{{ $year->id }}">{{ $year->name }}</option>@endforeach
    </flux:select>
    <flux:select label="Term" wire:model.live="termId" :disabled="$this->academicYearId === ''">
        <option value="">All terms</option>
        @foreach ($this->terms as $term)<option wire:key="filter-term-{{ $term->id }}" value="{{ $term->id }}">{{ $term->name }}</option>@endforeach
    </flux:select>
    <flux:input label="From date" type="date" wire:model.live="dateFrom" />
    <flux:input label="To date" type="date" wire:model.live="dateTo" />
</div>
