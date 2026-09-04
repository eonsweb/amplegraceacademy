<?php

use App\Support\Authorization\Permissions;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Terms')] class extends Component {
    public function mount(): void
    {
        Gate::authorize(Permissions::CLASSES_VIEW);
    }
};
?>

<x-academic.layout heading="Terms" subheading="Configure academic terms for each academic year.">
    <div class="grid gap-4">
        <div class="max-w-sm">
            <flux:select label="Academic Year" disabled>
                <flux:select.option selected>No academic years configured</flux:select.option>
            </flux:select>
        </div>

        <x-app.panel title="Terms">
            <x-academic.empty-state icon="calendar-days" heading="No terms to display" description="Select an academic year to view only its terms and identify the current term." />
        </x-app.panel>
    </div>
</x-academic.layout>
