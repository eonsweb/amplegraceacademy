<?php

use App\Actions\Students\ImportStudents;
use App\Models\StudentImport;
use App\Models\StudentImportRow;
use App\Support\StudentImportReader;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use App\Support\Authorization\Permissions;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Import Students')] class extends Component
{
    use WithFileUploads, WithPagination;

    public mixed $workbook = null;
    public string $enrollmentDate = '';
    #[Url] public string $batch = '';
    public string $filter = '';
    public bool $confirmed = false;
    public bool $showEditor = false;
    #[Locked] public ?int $editingRow = null;
    /** @var array<int, string> */
    public array $fields = [];
    #[Locked] public bool $generateNumber = false;
    public bool $admissionDateVerified = false;
    #[Locked] public ?string $generationAdmissionDate = null;
    #[Locked] public string $reviewNotice = '';
    #[Locked] public string $reviewNoticeVariant = 'success';

    public function mount(): void
    {
        if ($this->batch !== '' && StudentImport::query()->where('user_id', auth()->id())->whereKey($this->batch)->where('expires_at', '<=', now())->exists()) {
            session()->flash('importRecovery', 'This preview has expired. Upload the workbook again to review unresolved rows. Existing students will be detected and skipped; their records will not be changed.');
            $this->redirectRoute('students.import', navigate: true);
        }
    }

    public function boot(): void
    {
        app(ImportStudents::class)->authorize(auth()->user());
    }

    public function validateAndPreview(ImportStudents $imports): void
    {
        $this->validate([
            'workbook' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:5120'],
            'enrollmentDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);
        try {
            $import = $imports->prepare(auth()->user(), $this->workbook, $this->enrollmentDate);
            $this->batch = $import->id;
            $this->confirmed = false;
            $this->filter = '';
            $this->resetPage();
        } finally {
            $this->workbook?->delete();
            $this->workbook = null;
        }
        unset($this->import, $this->rows, $this->summary);
    }

    #[Computed]
    public function import(): ?StudentImport
    {
        return $this->batch === '' ? null : app(ImportStudents::class)->owned(auth()->user(), $this->batch);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, StudentImport> */
    #[Computed]
    public function recent(): \Illuminate\Database\Eloquent\Collection
    {
        return StudentImport::query()->where('user_id', auth()->id())->where('expires_at', '>', now())->latest()->limit(10)->get(['id', 'status', 'created_at']);
    }

    /** @return LengthAwarePaginator<int, StudentImportRow> */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        return StudentImportRow::query()->where('student_import_id', $this->import?->id ?? '')
            ->when($this->filter === 'unresolved', fn ($query) => $query->whereIn('status', ['review', 'error', 'failed']))
            ->when($this->filter !== '' && $this->filter !== 'unresolved', fn ($query) => $query->where('status', $this->filter))
            ->orderBy('row_number')->paginate(20);
    }

    /** @return array<string, int> */
    #[Computed]
    public function summary(): array
    {
        $counts = StudentImportRow::query()->where('student_import_id', $this->import?->id ?? '')
            ->selectRaw('status, COUNT(*) AS total, SUM(guardians_created) AS created, SUM(guardians_linked) AS linked')
            ->groupBy('status')->get();
        $statuses = $counts->pluck('total', 'status')->all();

        return [
            'Rows examined' => (int) $counts->sum('total'),
            'Ready' => (int) ($statuses['ready'] ?? 0) + (int) ($statuses['warning'] ?? 0),
            'Students created' => (int) ($statuses['imported'] ?? 0),
            'Skipped (existing)' => (int) ($statuses['exists'] ?? 0),
            'Requires Review' => (int) ($statuses['review'] ?? 0),
            'Errors / failed' => (int) ($statuses['error'] ?? 0) + (int) ($statuses['failed'] ?? 0),
            'Pending' => (int) ($statuses['pending'] ?? 0),
            'Guardians created' => (int) $counts->sum('created'),
            'Existing guardians linked' => (int) $counts->sum('linked'),
            'Enrollments created' => (int) ($statuses['imported'] ?? 0),
        ];
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function editRow(int $id, ImportStudents $imports): void
    {
        $import = $imports->owned(auth()->user(), $this->batch);
        abort_if($import->status === 'importing', 409);
        $row = $import->rows()->findOrFail($id);
        abort_if($row->status === 'imported', 409);
        $this->editingRow = $row->id;
        $this->resetValidation();
        $this->reviewNotice = '';
        $this->fields = array_map(fn (string $header): string => (string) ($row->payload[$header] ?? ''), StudentImportReader::HEADERS);
        $this->generateNumber = false;
        $this->admissionDateVerified = false;
        $this->generationAdmissionDate = null;
        $this->showEditor = true;
    }

    public function requestNumberGeneration(ImportStudents $imports): void
    {
        $row = $imports->owned(auth()->user(), $this->batch)->rows()->findOrFail($this->editingRow);
        abort_if(in_array($row->status, ['imported', 'exists'], true), 409, 'Existing student admission numbers cannot be replaced here.');
        $this->validate([
            'admissionDateVerified' => ['accepted'],
            'fields.6' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], [
            'admissionDateVerified.accepted' => 'Verify the original admission date before requesting a number.',
            'fields.6.required' => 'Enter the verified original admission date first.',
        ]);
        $this->fields[0] = '';
        $this->generateNumber = true;
        $this->generationAdmissionDate = $this->fields[6];
    }

    public function cancelNumberGeneration(ImportStudents $imports): void
    {
        $row = $imports->owned(auth()->user(), $this->batch)->rows()->findOrFail($this->editingRow);
        abort_if($row->status === 'imported', 409);
        $this->fields[0] = (string) ($row->payload['Admission Number'] ?? '');
        $this->generateNumber = false;
        $this->admissionDateVerified = false;
        $this->generationAdmissionDate = null;
        $this->resetValidation();
    }

    #[Computed]
    public function editingRecord(): ?StudentImportRow
    {
        return $this->editingRow === null ? null : app(ImportStudents::class)->owned(auth()->user(), $this->batch)->rows()->findOrFail($this->editingRow);
    }

    public function saveRow(ImportStudents $imports): void
    {
        $this->validate(['fields' => ['required', 'array', 'size:23'], 'fields.*' => ['nullable', 'string', 'max:5000']]);
        if ($this->generateNumber) {
            $this->validate([
                'fields.0' => ['prohibited'],
                'fields.6' => ['required', \Illuminate\Validation\Rule::in([$this->generationAdmissionDate])],
                'admissionDateVerified' => ['accepted'],
            ], [
                'fields.0.prohibited' => 'Cancel generation to keep or correct a supplied admission number.',
                'fields.6.in' => 'The admission date changed. Verify it and request generation again.',
            ]);
        }
        $imports->correct(auth()->user(), $this->batch, (int) $this->editingRow,
            array_combine(StudentImportReader::HEADERS, array_values($this->fields)), $this->generateNumber);
        unset($this->editingRecord);
        $row = $this->editingRecord;
        $ready = in_array($row->status, ['ready', 'warning'], true);
        $this->reviewNoticeVariant = $ready ? 'success' : 'warning';
        $this->reviewNotice = 'Excel row '.$row->row_number.($ready
            ? ': corrections saved and revalidated. Ready to import after confirmation.'
            : ': corrections saved to the preview. Review the remaining messages before importing.');
        $this->showEditor = ! $ready;
        if ($ready) {
            $this->fields = [];
            $this->editingRow = null;
        }
        $this->confirmed = false;
        unset($this->import, $this->rows, $this->summary);
        if ($this->getPage() > $this->rows->lastPage()) {
            $this->setPage($this->rows->lastPage());
            unset($this->rows);
        }
    }

    public function confirmImport(ImportStudents $imports): void
    {
        $this->validate(['confirmed' => ['accepted']]);
        $imports->confirm(auth()->user(), $this->batch);
        unset($this->import, $this->rows, $this->summary);
    }

    public function processChunk(ImportStudents $imports): bool
    {
        $imports->process(auth()->user(), $this->batch);
        unset($this->import, $this->rows, $this->summary);

        return $this->import?->status === 'importing';
    }

    public function removeImport(ImportStudents $imports): void
    {
        $imports->discard(auth()->user(), $this->batch);
        $this->batch = '';
        $this->confirmed = false;
        unset($this->import, $this->rows, $this->summary);
    }

    public function errorReport(ImportStudents $imports): StreamedResponse
    {
        $import = $imports->owned(auth()->user(), $this->batch);

        return response()->streamDownload(function () use ($import): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Excel row', 'Status', 'Messages'], ',', '"', '');
            foreach ($import->rows()->orderBy('row_number')->cursor() as $row) {
                $cells = [(string) $row->row_number, $row->status, implode(' ', $row->messages)];
                $cells = array_map(fn (string $cell): string => preg_match('/^[\s]*[=+\-@]/u', $cell) ? "'".$cell : $cell, $cells);
                fputcsv($stream, $cells, ',', '"', '');
            }
            fclose($stream);
        }, 'student-import-results.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function template(): StreamedResponse
    {
        app(ImportStudents::class)->authorize(auth()->user());

        return response()->streamDownload(function (): void {
            $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
            $book->getActiveSheet()->setTitle('Student Import')->fromArray(StudentImportReader::HEADERS);
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);
            $writer->setPreCalculateFormulas(false)->save('php://output');
            $book->disconnectWorksheets();
        }, 'Student_Import_Template.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store']);
    }
};
?>

<div class="grid gap-5" x-data="{ running: false, stopped: false, failed: false, async run() { if (this.running) return; this.running = true; this.stopped = false; this.failed = false; try { while (!this.stopped && await $wire.processChunk()) {} } catch (error) { this.failed = true; } finally { this.running = false; } } }">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><flux:heading size="xl">Import Students</flux:heading><flux:text>Upload → Validate → Preview → Confirm import → Results</flux:text></div>
        <flux:button :href="route('students.index')" wire:navigate>Student directory</flux:button>
    </div>
    <flux:callout>New students only. Existing students and their enrollments are never updated. Only the Student Import sheet is read. Birth date and a second guardian are optional; at least one complete guardian and an original admission date are required. Correct review rows below or import the ready rows first.</flux:callout>
    @if($batch === '')
        @if(session('importRecovery'))<flux:callout variant="warning">{{ session('importRecovery') }}</flux:callout>@endif
        <flux:text>If a preview has expired or was removed, upload the workbook again. Existing students are detected and skipped; correct only unresolved rows using verified information.</flux:text>
        <x-app.panel title="1. Upload and validate">
            <form wire:submit="validateAndPreview" class="grid gap-4 p-5">
                <flux:input type="file" wire:model="workbook" accept=".xlsx" label="Excel workbook" description="Use your existing enhanced workbook or download a blank template. Maximum 5 MB, 1,000 data rows and 20 MB expanded content." />
                <flux:input type="date" wire:model="enrollmentDate" label="Enrollment date for the placements in this file" description="Enter the actual placement date for the academic year in the spreadsheet. This is separate from each student's original admission date. For different placement dates, use separate uploads." required />
                <flux:text>Dates may be Excel dates, YYYY-MM-DD or DD/MM/YYYY. Keep phone numbers as text where possible. Formula admission numbers need a reliable saved Excel result; formulas are never executed.</flux:text>
                <div class="flex flex-wrap gap-3"><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="validateAndPreview,workbook">Validate and preview</flux:button><flux:button type="button" wire:click="template">Download blank template</flux:button></div>
                <p wire:loading wire:target="workbook,validateAndPreview" role="status" class="text-sm">Uploading and validating. No student records are being created.</p>
            </form>
        </x-app.panel>
        @if($this->recent->isNotEmpty())
            <x-app.panel title="Resume an import"><div class="grid gap-2 p-4">@foreach($this->recent as $item)<flux:button :href="route('students.import', ['batch' => $item->id])" wire:navigate>{{ $item->created_at->format('j M H:i') }} · {{ $item->status }}</flux:button>@endforeach</div></x-app.panel>
        @endif
    @else
        @php($import = $this->import)
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
            @foreach($this->summary as $label => $count)<div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700"><p class="text-xs text-zinc-500">{{ $label }}</p><p class="text-2xl font-semibold">{{ $count }}</p></div>@endforeach
        </div>
        <flux:text>Enrollment date: {{ $import->enrollment_date }}. Private preview expires {{ $import->expires_at->format('j M Y H:i') }}. Reference: {{ $import->id }}.</flux:text>
        @error('import')<flux:callout variant="danger">{{ $message }}</flux:callout>@enderror
        @if($reviewNotice !== '')<flux:callout :variant="$reviewNoticeVariant" role="status">{{ $reviewNotice }}</flux:callout>@endif
        <div class="flex flex-wrap items-center gap-3">
            <flux:button variant="primary" color="amber" wire:click="$set('filter', 'review')">Requires Review ({{ $this->summary['Requires Review'] }})</flux:button>
            <flux:select wire:model.live="filter" aria-label="Filter rows by status"><option value="">All rows</option><option value="unresolved">All unresolved (review, errors, failed)</option>@foreach(['ready' => 'Ready', 'warning' => 'Warning', 'review' => 'Requires Review', 'error' => 'Error', 'exists' => 'Already Exists', 'pending' => 'Pending', 'imported' => 'Imported', 'failed' => 'Failed'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</flux:select>
            <flux:button wire:click="errorReport">Download CSV results</flux:button>
            @if($import->status !== 'importing')<flux:button wire:click="removeImport" wire:confirm="Remove this private preview? Successfully imported students remain unchanged.">Remove preview</flux:button>@endif
        </div>
        @if($import->status === 'preview')
            <div class="grid gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:checkbox wire:model="confirmed" label="I reviewed the preview and approve importing ready and warning rows. Existing and review rows will be skipped or rejected." />
                <flux:error name="confirmed" />
                <flux:button class="justify-self-start" wire:click="confirmImport" variant="primary" wire:loading.attr="disabled">Confirm import</flux:button>
            </div>
        @elseif($import->status === 'importing')
            <div class="flex flex-wrap items-center gap-3" x-init="run()">
                <flux:button x-on:click="run()" x-bind:disabled="running" variant="primary">Resume import</flux:button>
                <flux:button x-on:click="stopped = true" x-bind:disabled="!running">Pause after current request</flux:button>
                <span role="status" x-show="running">Importing up to five students per request…</span>
                <p x-show="failed" role="alert">The request stopped. Resume safely; completed students will not be imported again.</p>
            </div>
        @else
            <flux:callout variant="success">Processing finished. Review the totals and row messages. You can correct rejected rows and confirm another pass; successfully imported students remain unchanged.</flux:callout>
        @endif
        <x-app.panel title="Preview and results">
            <div class="overflow-x-auto"><table class="w-full min-w-3xl text-left text-sm"><thead class="border-b text-xs uppercase text-zinc-500"><tr>@foreach(['Row', 'Student', 'Admission no.', 'Class / Year', 'Guardian', 'Status / Action'] as $heading)<th class="p-3">{{ $heading }}</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach($this->rows as $row)
                        @php($data = $row->payload)
                        <tr wire:key="import-row-{{ $row->id }}"><td class="p-3">{{ $row->row_number }}</td><td class="p-3">{{ $data['First Name'] }} {{ $data['Middle Name'] }} {{ $data['Last Name'] }}</td><td class="p-3">{{ $row->generate_number ? 'Generate on import' : ($data['Admission Number'] ?: 'Missing') }}</td><td class="p-3">{{ $data['Class Level'] }}<br>{{ $data['Academic Year'] }}</td><td class="p-3">{{ $data['Guardian 1 First Name'] }} {{ $data['Guardian 1 Last Name'] }}</td><td class="p-3"><flux:badge :color="match ($row->status) { 'review', 'warning' => 'amber', 'imported', 'ready' => 'green', 'error', 'failed' => 'red', default => 'zinc' }">{{ $row->status === 'review' ? 'Requires Review' : str($row->status)->headline() }}</flux:badge>@if($row->status !== 'imported' && $import->status !== 'importing')<flux:button size="sm" :variant="$row->status === 'review' ? 'primary' : 'outline'" :color="$row->status === 'review' ? 'amber' : null" wire:click="editRow({{ $row->id }})" wire:loading.attr="disabled">Review / correct</flux:button>@endif</td></tr>
                        @if($row->messages)<tr wire:key="import-message-{{ $row->id }}"><td colspan="6" class="px-3 pb-3 text-xs text-zinc-600 dark:text-zinc-300"><ul class="list-inside list-disc">@foreach($row->messages as $message)<li>{{ $message }}</li>@endforeach</ul></td></tr>@endif
                    @endforeach
                    @if($this->rows->isEmpty())<tr><td colspan="6" class="p-4 text-zinc-600 dark:text-zinc-300">No rows match this filter. Choose All rows to view the other results.</td></tr>@endif
                </tbody></table></div>
            <div class="p-4">{{ $this->rows->links() }}</div>
        </x-app.panel>
    @endif
    <flux:modal wire:model="showEditor" class="w-full max-w-4xl">
        <form wire:submit="saveRow" class="grid gap-4">
            <flux:heading size="lg">Correct preview row</flux:heading>
            @if($showEditor && $this->editingRecord)
                @php($editing = $this->editingRecord)
                <flux:text>Excel row {{ $editing->row_number }} — {{ $editing->payload['First Name'] }} {{ $editing->payload['Middle Name'] }} {{ $editing->payload['Last Name'] }}</flux:text>
                @if($reviewNotice !== '')<flux:callout :variant="$reviewNoticeVariant" role="status">{{ $reviewNotice }}</flux:callout>@endif
                @if($editing->messages)
                    <flux:callout :variant="in_array($editing->status, ['error', 'failed'], true) ? 'danger' : 'warning'" heading="Validation messages">
                        <ul class="list-inside list-disc">@foreach($editing->messages as $index => $message)<li wire:key="editor-message-{{ $editing->id }}-{{ $index }}">{{ $message }}</li>@endforeach</ul>
                    </flux:callout>
                @endif
            @endif
            <flux:text>Changes apply only to this private preview. Dates use YYYY-MM-DD. Guardian 1 is primary when provided; otherwise the complete Guardian 2 is primary. Remove all optional second-guardian values to omit that guardian.</flux:text>
            <div class="grid gap-3 md:grid-cols-2">@foreach(StudentImportReader::HEADERS as $index => $header)<flux:input wire:model="fields.{{ $index }}" :label="$header" :type="in_array($header, ['Date of Birth', 'Admission Date'], true) ? 'date' : 'text'" />@endforeach</div>
            <flux:text>Keep the existing admission number when correcting guardians or optional details. To correct a typo, edit Admission Number above. Numbers are never generated just by saving corrections.</flux:text>
            @if($showEditor && $this->editingRecord?->status !== 'exists')
                <details class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" @if($generateNumber) open @endif>
                    <summary class="cursor-pointer font-medium">Request a generated admission number</summary>
                    <div class="mt-3 grid gap-3">
                        <flux:text>Use only when the original number is missing or confirmed invalid. This clears the number in this editor. Saving revalidates the preview; a number is assigned only after import confirmation. Existing students cannot be renumbered here.</flux:text>
                        <flux:checkbox wire:model="admissionDateVerified" label="I verified the original Admission Date against trusted school records." />
                        <flux:error name="admissionDateVerified" />
                        <flux:button type="button" wire:click="requestNumberGeneration" wire:confirm="Clear the admission number in this editor and request a new number using the verified admission date? Existing student records will not be changed." wire:loading.attr="disabled">Clear number and request generation</flux:button>
                        @if($generateNumber)
                            <flux:callout variant="warning">Generation requested for admission date {{ $generationAdmissionDate }}. Save and revalidate before confirming import.</flux:callout>
                            <flux:button type="button" wire:click="cancelNumberGeneration">Cancel generation and restore preview number</flux:button>
                        @endif
                    </div>
                </details>
            @endif
            <div class="flex justify-end gap-3"><flux:button type="submit" variant="primary" wire:loading.attr="disabled">Save and revalidate</flux:button></div>
        </form>
    </flux:modal>
</div>
