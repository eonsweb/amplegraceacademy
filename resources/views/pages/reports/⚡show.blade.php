<?php

use App\Support\Authorization\Permissions;
use App\Support\Academic\AcademicContext;
use App\Support\Reports\ReportCatalog;
use App\Support\Reports\ReportData;
use App\Support\Reports\ReportFilters;
use App\Support\Reports\FinancialReports;
use App\Support\Settings\SystemSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

return new #[Title('School Report')] class extends Component {
    use WithPagination;

    #[Locked]
    public string $report = '';

    /** @var array<string, string> */
    #[Url]
    public array $filters = [];

    /** @var array<string, string> */
    #[Locked]
    public array $appliedFilters = [];

    public string $assessmentSearch = '';

    public function boot(): void
    {
        Gate::authorize(Permissions::REPORTS_VIEW);
    }

    public function mount(string $report, AcademicContext $context): void
    {
        ReportCatalog::authorize(auth()->user(), $report);
        $this->report = $report;
        $defaults = [];
        if (in_array($report, ['class-results', 'assessment-results'], true)) {
            $defaults = ['academic_year_id' => (string) ($context->currentYearId() ?? ''), 'term_id' => (string) ($context->currentTermId() ?? '')];
        }
        if ($report === 'attendance-daily') {
            $defaults['date'] = now(app(SystemSettings::class)->timezone())->toDateString();
        }
        if ($report === 'outstanding') {
            $defaults['status'] = 'outstanding';
        }
        $this->filters = array_replace(array_fill_keys(ReportCatalog::get($report)['filters'], ''), $defaults, $this->filters);
        $this->appliedFilters = $this->filters;
    }

    public function updatedFilters(mixed $value, ?string $key): void
    {
        if ($key === 'academic_year_id' && array_key_exists('term_id', $this->filters)) {
            $this->filters['term_id'] = '';
        }
        if (in_array($key, ['academic_year_id', 'term_id', 'class_level_id', 'subject_id'], true)
            && array_key_exists('assessment_id', $this->filters)) {
            $this->filters['assessment_id'] = '';
        }
        $this->resetPage();
    }

    public function updatedAssessmentSearch(): void
    {
        $this->appliedFilters = [];
        $this->resetPage();
    }

    public function applyFilters(): void
    {
        ReportCatalog::authorize(auth()->user(), $this->report);
        $this->appliedFilters = $this->filters;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->assessmentSearch = '';
        $this->filters = array_fill_keys(ReportCatalog::get($this->report)['filters'], '');
        $this->appliedFilters = $this->filters;
        $this->resetPage();
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function definition(): array
    {
        return ReportCatalog::get($this->report);
    }

    /** @return array<string, array<int|string, string>> */
    #[Computed]
    public function options(): array
    {
        return ReportFilters::options(auth()->user(), $this->report, $this->filters, $this->assessmentSearch);
    }

    public function render(): View
    {
        ReportCatalog::authorize(auth()->user(), $this->report);
        $this->resetValidation();
        $summary = [];
        $activeFilters = [];
        $valid = false;
        $rows = new LengthAwarePaginator([], 0, app(SystemSettings::class)->recordsPerPage());
        if ($this->filters !== $this->appliedFilters) {
            return $this->getProvidedView()->with(compact('rows', 'summary', 'activeFilters', 'valid'));
        }
        try {
            $data = new ReportData(auth()->user(), $this->report, $this->filters);
            $rows = $data->page(app(SystemSettings::class)->recordsPerPage(), $this->getPage());
            $summary = $data->summary();
            foreach ($data->filters as $key => $value) {
                if ($value !== '') {
                    $activeFilters[ReportFilters::labels()[$key]] = $this->options[$key][$value] ?? $value;
                }
            }
            $valid = true;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError('filters.'.$field, $messages[0]);
            }
        }

        return $this->getProvidedView()->with(compact('rows', 'summary', 'activeFilters', 'valid'));
    }
};
?>
<section class="grid min-w-0 gap-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div><flux:heading size="xl" level="1">{{ $this->definition['title'] }}</flux:heading><flux:text>{{ $this->definition['note'] }}</flux:text></div>
        <flux:button :href="route('reports.index')" wire:navigate>All reports</flux:button>
    </div>
    <form wire:submit="applyFilters" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($this->definition['filters'] as $field)
            @php($label = ReportFilters::labels()[$field])
            <div wire:key="report-filter-{{ $field }}">
                @if ($field === 'assessment_id')
                    <flux:input label="Find an assessment" wire:model.live.debounce.400ms="assessmentSearch" maxlength="100" placeholder="Assessment name or exact ID" />
                    <flux:text class="my-2">Showing up to 100 matches and the selected assessment.</flux:text>
                @endif
                @if (array_key_exists($field, $this->options))
                    @if (in_array($field, ['academic_year_id', 'term_id', 'class_level_id', 'subject_id'], true))
                        <flux:select :label="$label" wire:model.live="filters.{{ $field }}">
                            <option value="">All / select {{ strtolower($label) }}</option>
                            @foreach ($this->options[$field] as $id => $name)<option wire:key="option-{{ $field }}-{{ $id }}" value="{{ $id }}">{{ $name }}</option>@endforeach
                        </flux:select>
                    @else
                        <flux:select :label="$label" wire:model="filters.{{ $field }}">
                            <option value="">All / select {{ strtolower($label) }}</option>
                            @foreach ($this->options[$field] as $id => $name)<option wire:key="option-{{ $field }}-{{ $id }}" value="{{ $id }}">{{ $name }}</option>@endforeach
                        </flux:select>
                    @endif
                @else
                    <flux:input :label="$label" :type="str_starts_with($field, 'date') ? 'date' : 'text'" wire:model="filters.{{ $field }}" />
                @endif
            </div>
        @endforeach
        <div class="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-4">
            <flux:button type="submit" variant="primary">Apply filters</flux:button>
            <flux:button wire:click="resetFilters" type="button">Reset filters</flux:button>
            @if ($valid)
                <flux:button :href="route('reports.export', ['report' => $report, 'format' => 'print', 'filters' => $filters])" target="_blank">Print report</flux:button>
                <flux:button :href="route('reports.export', ['report' => $report, 'format' => 'csv', 'filters' => $filters])">Download CSV</flux:button>
            @endif
        </div>
    </form>
    <flux:error name="filters.filters" />
    @if ($filters !== $appliedFilters)
        <flux:callout>Filters have changed. Apply filters to generate the report.</flux:callout>
    @endif
    <div wire:loading role="status" class="text-sm text-zinc-500">Updating report…</div>
    @if ($activeFilters)
        <div class="flex flex-wrap gap-2" aria-label="Active report filters">
            @foreach ($activeFilters as $label => $value)<flux:badge wire:key="active-{{ $label }}">{{ $label }}: {{ $value }}</flux:badge>@endforeach
        </div>
    @endif
    @if ($summary)
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($summary as $label => $value)
                <x-app.panel :title="$label" wire:key="summary-{{ $label }}"><p class="break-words p-4 text-xl font-semibold tabular-nums">{{ $value ?? '—' }}</p></x-app.panel>
            @endforeach
        </div>
    @endif
    <x-app.panel :title="$this->definition['title']">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr>@foreach ($this->definition['columns'] as $label)<th scope="col" class="whitespace-nowrap p-4">{{ $label }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr wire:key="report-row-{{ $rows->currentPage() }}-{{ $loop->index }}" class="border-t border-zinc-200 dark:border-zinc-700">
                            @foreach ($this->definition['columns'] as $column => $label)
                                <td class="min-w-24 max-w-lg p-4">
                                    @if ($report === 'class-enrollment' && $column === 'class_name')
                                        <a class="underline underline-offset-4" href="{{ route('reports.show', ['report' => 'enrollment', 'filters' => ['academic_year_id' => $row->academic_year_id, 'class_level_id' => $row->class_level_id]]) }}" wire:navigate>{{ $row->class_name }}</a>
                                    @else
                                        {{ FinancialReports::format($column, $row->$column ?? null) }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($this->definition['columns']) }}" class="p-8 text-center">{{ $valid ? 'No records found for these filters.' : 'Select valid filters to generate this report.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $rows->links() }}</div>
    </x-app.panel>
</section>
