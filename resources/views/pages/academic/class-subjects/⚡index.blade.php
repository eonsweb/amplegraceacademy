<?php

use App\Support\Authorization\Permissions;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Class Subjects')] class extends Component {
    public function mount(): void
    {
        abort_unless(Gate::any([Permissions::CLASSES_VIEW, Permissions::SUBJECTS_VIEW]), 403);
    }
};
?>

<x-academic.layout heading="Class Subjects" subheading="Assign subjects and teachers to classes for an academic year.">
    <div class="grid gap-5">
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select label="Academic Year" disabled>
                <flux:select.option selected>No academic years configured</flux:select.option>
            </flux:select>
            <flux:select label="Class Section" disabled>
                <flux:select.option selected>No class sections configured</flux:select.option>
            </flux:select>
        </div>

        <x-app.panel title="Subject assignments">
            <x-academic.empty-state icon="check-circle" heading="Select a class section" description="Subject and optional teacher assignments will be edited here and saved together as one batch." />
        </x-app.panel>

        @if (auth()->user()->canAny([Permissions::CLASSES_UPDATE, Permissions::SUBJECTS_UPDATE]))
            <div class="flex justify-end">
                <flux:button variant="primary" disabled title="Class-subject data management is not configured yet">Save assignments</flux:button>
            </div>
        @endif
    </div>
</x-academic.layout>
