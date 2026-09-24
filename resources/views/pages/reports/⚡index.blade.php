<?php

use App\Support\Authorization\Permissions;
use App\Support\Reports\ReportCatalog;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Reports')] class extends Component {
    public function boot(): void
    {
        Gate::authorize(Permissions::REPORTS_VIEW);
    }
};
?>
<section class="grid gap-6">
    <div><flux:heading size="xl" level="1">Reports</flux:heading><flux:text>Review school records by academic period, print a report or download a CSV.</flux:text></div>
    @foreach (['Academic', 'Attendance', 'Financial'] as $group)
        @php($reports = collect(ReportCatalog::all())->filter(fn ($report) => $report['group'] === $group && auth()->user()->can($report['permission'])))
        @if ($reports->isNotEmpty())
            <section wire:key="report-group-{{ $group }}" class="grid gap-3">
                <flux:heading size="lg" level="2">{{ $group }} reports</flux:heading>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($reports as $key => $report)
                        <a wire:key="report-{{ $key }}" href="{{ route('reports.show', $key) }}" wire:navigate class="rounded-xl border border-zinc-200 bg-white p-5 hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800">
                            <h3 class="font-semibold">{{ $report['title'] }}</h3>
                            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ $report['note'] }}</p>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach
    @if (! collect(ReportCatalog::all())->contains(fn ($report) => auth()->user()->can($report['permission'])))
        <flux:callout>Your account can open Reports, but no report categories have been granted. Ask an administrator for the appropriate access.</flux:callout>
    @endif
</section>
