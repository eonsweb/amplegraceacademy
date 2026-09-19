<?php
use App\Actions\Fees\RecordPayment;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use App\Support\Fees\FeeLedger;
use App\Support\Settings\SystemSettings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
return new #[Title('Record Payment')] class extends Component {
use WithPagination;
public string $search = '';
#[Locked] public ?int $studentId = null;
#[Locked] public string $submissionKey;
#[Locked] public ?int $savedPaymentId = null;
public string $invoiceId = '';
public string $amount = '';
public string $paymentDate = '';
public string $paymentMethod = 'cash';
public string $reference = '';
public string $notes = '';
public function boot(): void { Gate::authorize(Permissions::PAYMENTS_RECORD); }
public function mount(): void { $this->submissionKey=(string)Str::uuid(); $this->paymentDate=today()->toDateString(); }
/** @return \Illuminate\Support\Collection<int, \App\Models\Student> */
#[Computed] public function students(): \Illuminate\Support\Collection {
$search=trim($this->search);
if(mb_strlen($search)<2) { return collect(); }
return Student::query()->matchingNameOrAdmission($search)
->orderByRaw('CASE WHEN admission_number = ? THEN 0 ELSE 1 END',[$search])->orderBy('last_name')->orderBy('id')->limit(15)->get(['id','first_name','middle_name','last_name','admission_number']);
}
#[Computed] public function student(): ?Student { return $this->studentId === null ? null : Student::query()->findOrFail($this->studentId); }
/** @return \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\Invoice> */
#[Computed] public function invoices(): \Illuminate\Pagination\LengthAwarePaginator { return FeeLedger::invoices()->where('student_id',$this->studentId ?? 0)->whereNull('voided_at')->where('outstanding','>',0)->orderBy('issue_date')->orderBy('id')->paginate(10); }
public function selectStudent(int $id): void { Gate::authorize(Permissions::PAYMENTS_RECORD); Student::query()->findOrFail($id); $this->studentId=$id; $this->search=''; $this->newPayment(); $this->resetPage(); }
public function newPayment(): void { $this->reset('invoiceId','amount','reference','notes','savedPaymentId'); $this->submissionKey=(string)Str::uuid(); $this->resetValidation(); unset($this->invoices); }
public function save(RecordPayment $record): void {
Gate::authorize(Permissions::PAYMENTS_RECORD);
if($this->savedPaymentId !== null) { return; }
$payment=$record->handle(auth()->user(),['student_id'=>$this->studentId,'invoice_id'=>$this->invoiceId,'amount'=>$this->amount,'payment_date'=>$this->paymentDate,'payment_method'=>$this->paymentMethod,'reference'=>$this->reference ?: null,'notes'=>$this->notes ?: null,'submission_key'=>$this->submissionKey]);
$this->savedPaymentId=$payment->id; unset($this->invoices); \Flux\Flux::toast(variant:'success',text:'Payment saved. Receipt '.$payment->receipt_number);
}
};
?>
<x-fees.layout heading="Record Payment" subheading="Search by admission number or student name. Older unpaid invoices remain available for payment.">
<flux:input label="Find student" wire:model.live.debounce.400ms="search" placeholder="Enter at least two characters" />
@if($search !== '' && mb_strlen(trim($search)) >= 2)
<x-app.panel title="Matching students"><div class="grid gap-2 p-4">
@forelse($this->students as $student)<flux:button wire:key="student-{{ $student->id }}" wire:click="selectStudent({{ $student->id }})" class="justify-start">{{ $student->admission_number }} — {{ $student->fullName() }}</flux:button>
@empty<p class="text-zinc-500">No matching students.</p>
@endforelse</div></x-app.panel>
@endif
@if($this->student)
<x-app.panel :title="$this->student->fullName().' · '.$this->student->admission_number"><div class="grid gap-4 p-5">
@if($savedPaymentId)
<flux:callout variant="success" heading="Payment saved" text="This entry has been recorded successfully." />
<div class="flex flex-wrap gap-3">
@can(Permissions::RECEIPTS_PRINT)<flux:button :href="route('fees.receipt', $savedPaymentId)" target="_blank" variant="primary">Print Receipt</flux:button>
@endcan<flux:button wire:click="newPayment">Record Another Payment</flux:button></div>
@else
<form wire:submit="save" class="grid gap-5">
<div class="overflow-x-auto"><table class="w-full min-w-2xl text-left text-sm"><thead><tr><th class="p-3">Select invoice</th><th class="p-3">Class / Period</th><th class="p-3">Total</th><th class="p-3">Paid</th><th class="p-3">Outstanding</th></tr></thead><tbody>
@forelse($this->invoices as $invoice)<tr wire:key="choose-{{ $invoice->id }}"><td class="p-3"><label class="flex items-center gap-2"><input type="radio" wire:model="invoiceId" value="{{ $invoice->id }}" name="invoiceId" class="accent-blue-700"><span class="text-xs">{{ $invoice->invoice_number }}</span></label></td><td class="p-3">{{ $invoice->class_name }}<p>{{ $invoice->academic_year_name }} / {{ $invoice->term_name }}</p></td>
@foreach(['total','paid','outstanding'] as $key)<td class="p-3 tabular-nums">{{ app(SystemSettings::class)->formatMoney($invoice->$key) }}</td>
@endforeach</tr>
@empty<tr><td colspan="5" class="p-6 text-center">This student has no outstanding invoices.</td></tr>
@endforelse
</tbody></table></div>{{ $this->invoices->links() }}
<flux:error name="student_id" /><flux:error name="invoice_id" />
<div class="grid gap-4 sm:grid-cols-3"><flux:input label="Amount" type="number" min="0.01" step="0.01" wire:model="amount" required /><flux:input label="Payment date" type="date" wire:model="paymentDate" required /><flux:select label="Payment method" wire:model="paymentMethod">
@foreach(\App\PaymentMethod::cases() as $method)<option value="{{ $method->value }}">{{ $method->label() }}</option>
@endforeach</flux:select></div>
<flux:error name="payment_date" /><flux:error name="payment_method" />
<flux:input label="Reference (optional)" wire:model="reference" maxlength="150" /><flux:textarea label="Notes (optional)" wire:model="notes" maxlength="1000" /><flux:error name="submission_key" />
<div><flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:confirm="Record this payment? Confirm the student, invoice and amount before continuing.">Save Payment</flux:button></div>
</form>
@endif
</div></x-app.panel>
@endif
</x-fees.layout>
