<?php
use App\Concerns\FiltersFeeContext;
use App\Support\Authorization\Permissions;
use App\Support\Fees\FeeLedger;
use App\Support\Settings\SystemSettings;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

use App\Actions\Fees\GenerateInvoices;
use Livewire\Attributes\Locked;
return new #[Title('Invoices')] class extends Component {
use FiltersFeeContext, WithPagination;
public string $issueDate = '';
public string $dueDate = '';
public string $status = '';
public string $studentId = '';
/** @var array{students: int, total: string, fingerprint: string}|null */
#[Locked] public ?array $preview = null;
/** @var list<string> */
#[Locked] public array $previewContext = [];
public function boot(): void { Gate::authorize(Permissions::INVOICES_VIEW); }
public function mount(): void { $this->issueDate = today()->toDateString(); }
public function updatedStatus(): void { $this->resetPage(); }
public function previewInvoices(GenerateInvoices $generate): void {
Gate::authorize(Permissions::INVOICES_GENERATE);
$this->preview = $generate->preview(auth()->user(),(int)$this->academicYearId,(int)$this->termId,(int)$this->classLevelId,$this->studentId !== '' ? (int)$this->studentId : null);
$this->previewContext = [$this->academicYearId,$this->termId,$this->classLevelId,$this->studentId];
}
public function generate(GenerateInvoices $generate): void {
Gate::authorize(Permissions::INVOICES_GENERATE);
if($this->preview === null || $this->previewContext !== [$this->academicYearId,$this->termId,$this->classLevelId,$this->studentId]) { $this->addError('fees','Preview the selected context before generating.'); return; }
$count = $generate->handle(auth()->user(),(int)$this->academicYearId,(int)$this->termId,(int)$this->classLevelId,$this->issueDate,$this->dueDate ?: null,$this->studentId !== '' ? (int)$this->studentId : null,$this->preview['fingerprint']);
$this->preview = null;
unset($this->invoices);
\Flux\Flux::toast(variant:'success',text:"Created $count invoices. Previously billed students were skipped.");
}
/** @return \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\Invoice> */
#[Computed] public function invoices(): \Illuminate\Pagination\LengthAwarePaginator {
return $this->invoiceQuery()->when($this->status === 'void',fn($q)=>$q->whereNotNull('voided_at'))
->when(in_array($this->status,['unpaid','partially_paid','paid'],true),fn($q)=>$q->whereNull('voided_at'))
->when($this->status === 'unpaid',fn($q)=>$q->where('paid',0)->where('outstanding','>',0))
->when($this->status === 'partially_paid',fn($q)=>$q->where('paid','>',0)->where('outstanding','>',0))
->when($this->status === 'paid',fn($q)=>$q->where('outstanding',0))
->orderByDesc('id')->paginate(app(SystemSettings::class)->recordsPerPage());
}
};
?>
<x-fees.layout heading="Invoices" subheading="Select a year, term and class to preview and generate bills. Existing invoices are retained and never duplicated.">
<x-fees.filters :classes="true" :search="true" />
<flux:select label="Invoice status" wire:model.live="status" class="max-w-xs"><option value="">All statuses</option>
@foreach(\App\InvoiceStatus::cases() as $value)<option value="{{ $value->value }}">{{ $value->label() }}</option>
@endforeach</flux:select>
@can(Permissions::INVOICES_GENERATE)
<x-app.panel title="Generate invoices"><div class="grid gap-4 p-5">
<div class="grid gap-3 sm:grid-cols-3"><flux:input label="Issue date" type="date" wire:model="issueDate" /><flux:input label="Due date (optional)" type="date" wire:model="dueDate" /><div class="self-end"><flux:button wire:click="previewInvoices" wire:loading.attr="disabled">Preview invoices</flux:button></div></div>
<flux:error name="academic_year_id" /><flux:error name="term_id" /><flux:error name="class_level_id" /><flux:error name="issue_date" /><flux:error name="due_date" /><flux:error name="fees" />
@if($preview)
<div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
<p class="font-semibold">{{ $this->years->firstWhere('id', (int)$previewContext[0])?->name }} / {{ $this->terms->firstWhere('id', (int)$previewContext[1])?->name }} / {{ $this->classes->firstWhere('id', (int)$previewContext[2])?->name }}</p>
<p class="py-2">{{ $preview['students'] }} eligible students · {{ app(SystemSettings::class)->formatMoney($preview['total']) }} per student</p>
<flux:button variant="primary" wire:click="generate" wire:confirm="Generate these student invoices? Charges will be preserved as historical records." wire:loading.attr="disabled" :disabled="$preview['students'] === 0">Generate Invoices</flux:button>
</div>
@endif
</div></x-app.panel>
@endcan
<x-app.panel title="Student invoices"><x-fees.invoice-table :invoices="$this->invoices" /></x-app.panel>
</x-fees.layout>
