@props(['classes' => false, 'search' => false])
<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <flux:select label="Academic year" wire:model.live="academicYearId"><option value="">All years</option>
@foreach($this->years as $year)<option value="{{ $year->id }}" wire:key="year-{{ $year->id }}">{{ $year->name }}</option>
@endforeach</flux:select>
    <flux:select label="Term" wire:model.live="termId"><option value="">All terms</option>
@foreach($this->terms as $term)<option value="{{ $term->id }}" wire:key="term-{{ $term->id }}">{{ $term->name }}
@if($this->academicYearId === '') ({{ $this->years->firstWhere('id', $term->academic_year_id)?->name }})
@endif</option>
@endforeach</flux:select>
    
@if($classes)<flux:select label="Class level" wire:model.live="classLevelId"><option value="">All classes</option>
@foreach($this->classes as $class)<option value="{{ $class->id }}" wire:key="class-{{ $class->id }}">{{ $class->name }}</option>
@endforeach</flux:select>
@endif
    
@if($search)<flux:input label="Student or invoice" wire:model.live.debounce.400ms="search" placeholder="Admission number, name or invoice" />
@endif
</div>
