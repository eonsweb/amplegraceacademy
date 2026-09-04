<?php

use App\Models\AcademicYear;
use App\Models\Term;
use App\Support\Academic\AcademicContext;
use App\Support\Authorization\Permissions;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Terms')] class extends Component {
    #[Url]
    public ?int $academicYearId = null;

    public function mount(AcademicContext $context): void
    {
        Gate::authorize(Permissions::CLASSES_VIEW);
        $this->academicYearId ??= $context->currentYearId() ?? AcademicYear::query()->orderByDesc('name')->value('id');
    }

    /** @return Collection<int, AcademicYear> */
    #[Computed]
    public function years(): Collection
    {
        return AcademicYear::query()->select(['id', 'name', 'is_current'])->orderByDesc('name')->limit(50)->get();
    }

    /** @return Collection<int, Term> */
    #[Computed]
    public function terms(): Collection
    {
        return Term::query()->select(['id', 'academic_year_id', 'name', 'term_order', 'is_current'])
            ->where('academic_year_id', $this->academicYearId ?? 0)
            ->orderBy('term_order')
            ->get();
    }

    public function makeCurrent(int $id, AcademicContext $context): void
    {
        Gate::authorize(Permissions::CLASSES_UPDATE);
        $term = Term::query()->where('academic_year_id', $this->academicYearId)->findOrFail($id);
        $context->setCurrentTerm($term);
        unset($this->terms, $this->years);
        Flux::toast(variant: 'success', text: 'Current term updated.');
    }
};
?>

<x-academic.layout heading="Terms" subheading="View the three standard terms and select the current term.">
    <div class="grid gap-4">
        <div class="w-full max-w-sm">
            <flux:select wire:model.live="academicYearId" label="Academic Year">
                <flux:select.option value="">Select a year</flux:select.option>
                @foreach($this->years as $year)
                    <flux:select.option value="{{ $year->id }}">{{ $year->name }}{{ $year->is_current ? ' (Current)' : '' }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @error('term')
            <flux:callout variant="danger" icon="exclamation-circle" heading="{{ $message }}" />
        @enderror

        <x-app.panel title="Configured Terms">
            <div class="overflow-x-auto">
                <table class="w-full min-w-lg text-left text-sm">
                    <thead class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-800">
                        <tr>
                            <th class="px-4 py-3">Term</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse($this->terms as $term)
                            <tr wire:key="term-{{ $term->id }}">
                                <td class="px-4 py-3 font-semibold">{{ $term->name }}</td>
                                <td class="px-4 py-3">
                                    @if($term->is_current)
                                        <flux:badge color="green">Current</flux:badge>
                                    @else
                                        <span class="text-zinc-500">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @can(Permissions::CLASSES_UPDATE)
                                        @if(! $term->is_current && $this->years->firstWhere('id', $term->academic_year_id)?->is_current)
                                            <flux:button size="sm" variant="ghost" wire:click="makeCurrent({{ $term->id }})">Set current</flux:button>
                                        @elseif(! $term->is_current)
                                            <span class="text-xs text-zinc-500">Set this academic year as current first</span>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3"><x-academic.empty-state class="border-0" icon="calendar-days" heading="No terms to display" description="Select an academic year to view its standard terms." /></td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-app.panel>
    </div>
</x-academic.layout>
