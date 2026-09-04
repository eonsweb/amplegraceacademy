<?php

use App\Support\Authorization\Permissions;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Class Sections')] class extends Component {
    public function mount(): void
    {
        Gate::authorize(Permissions::CLASSES_VIEW);
    }
};
?>

<x-academic.layout heading="Class Sections" subheading="Configure actual class groups within each academic level.">
    <div class="grid gap-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="w-full max-w-sm">
                <flux:select label="Class Level" disabled>
                    <flux:select.option selected>No class levels configured</flux:select.option>
                </flux:select>
            </div>
            @can(Permissions::CLASSES_CREATE)
                <flux:button variant="primary" icon="plus" disabled title="Class-section data management is not configured yet">Create Class Section</flux:button>
            @endcan
        </div>

        <x-app.panel title="Class sections">
            <x-academic.empty-state icon="book-open" heading="No class sections to display" description="Choose a class level to manage its Main, A, B, or other class groups." />
        </x-app.panel>
    </div>
</x-academic.layout>
