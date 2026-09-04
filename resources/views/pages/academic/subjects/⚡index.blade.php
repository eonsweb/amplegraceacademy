<?php

use App\Support\Authorization\Permissions;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Subjects')] class extends Component {
    public function mount(): void
    {
        Gate::authorize(Permissions::SUBJECTS_VIEW);
    }
};
?>

<x-academic.layout heading="Subjects" subheading="Manage subjects taught by the school.">
    <div class="grid gap-4">
        @can(Permissions::SUBJECTS_CREATE)
            <div class="flex justify-end">
                <flux:button variant="primary" icon="plus" disabled title="Subject data management is not configured yet">Create Subject</flux:button>
            </div>
        @endcan

        <x-app.panel title="Subjects">
            <div class="overflow-x-auto">
                <table class="w-full min-w-lg text-left text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800 dark:text-zinc-300">
                        <tr><th class="px-4 py-3">Subject name</th><th class="px-4 py-3">Code</th><th class="px-4 py-3">Status</th></tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="3" class="p-0"><x-academic.empty-state class="border-0" icon="building-library" heading="No subjects configured" description="Active and inactive subjects will be listed here without requiring destructive deletion." /></td></tr>
                    </tbody>
                </table>
            </div>
        </x-app.panel>
    </div>
</x-academic.layout>
