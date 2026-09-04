<?php

use App\Support\Authorization\Permissions;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Academic Years')] class extends Component {
    public function mount(): void
    {
        Gate::authorize(Permissions::CLASSES_VIEW);
    }
};
?>

<x-academic.layout heading="Academic Years" subheading="Manage school academic years and the current academic year.">
    <div class="grid gap-4">
        @can(Permissions::CLASSES_CREATE)
            <div class="flex justify-end">
                <flux:button variant="primary" icon="plus" disabled title="Academic year data management is not configured yet">Create Academic Year</flux:button>
            </div>
        @endcan

        <x-app.panel title="Configured academic years">
            <x-academic.empty-state icon="calendar-days" heading="No academic years configured" description="Academic years and the current-year marker will appear here once academic records are configured." />
        </x-app.panel>
    </div>
</x-academic.layout>
