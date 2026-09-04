<?php

use App\Support\Authorization\Permissions;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Class Levels')] class extends Component {
    public function mount(): void
    {
        Gate::authorize(Permissions::CLASSES_VIEW);
    }
};
?>

<x-academic.layout heading="Class Levels" subheading="Configure the school's grade or academic level structure.">
    <div class="grid gap-4">
        @can(Permissions::CLASSES_CREATE)
            <div class="flex justify-end">
                <flux:button variant="primary" icon="plus" disabled title="Class-level data management is not configured yet">Create Class Level</flux:button>
            </div>
        @endcan

        <x-app.panel title="Ordered class levels">
            <x-academic.empty-state icon="academic-cap" heading="No class levels configured" description="Class levels will appear here in teaching order, from the earliest level through the highest." />
        </x-app.panel>
    </div>
</x-academic.layout>
