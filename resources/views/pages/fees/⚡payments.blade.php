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

use App\Actions\Fees\VoidPayment;
use App\Models\Payment;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
return new #[Title('Payment History')] class extends Component {
use FiltersFeeContext, WithPagination;
#[Url] public string $dateFrom = '';
#[Url] public string $dateTo = '';
#[Url] public string $method = '';
#[Locked] public ?int $voidPaymentId = null;
public bool $showVoid = false;
public string $reason = '';
public function boot(): void { Gate::authorize(Permissions::PAYMENTS_VIEW); }
public function updated(string $name): void { if(in_array($name,['dateFrom','dateTo','method'],true)) { $this->resetPage(); } }
/** @return \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\Payment> */
#[Computed] public function payments(): \Illuminate\Pagination\LengthAwarePaginator {
$search=trim($this->search);
return FeeLedger::payments($this->academicYearId,$this->termId)->with('student:id,first_name,middle_name,last_name,admission_number')
->when($this->dateFrom !== '',fn($q)=>$q->where('payment_date','>=',$this->dateFrom))
->when($this->dateTo !== '',fn($q)=>$q->where('payment_date','<=',$this->dateTo))
->when($this->method !== '',fn($q)=>$q->where('payment_method',$this->method))
->when($search !== '',fn($q)=>$q->where(fn($q)=>$q->where('receipt_number','like','%'.$search.'%')->orWhereIn('student_id',\App\Models\Student::query()->matchingNameOrAdmission($search)->select('id'))))
->orderByDesc('payment_date')->orderByDesc('id')->paginate(app(SystemSettings::class)->recordsPerPage());
}
public function openVoid(int $id): void { Gate::authorize('void',Payment::query()->findOrFail($id)); $this->voidPaymentId=$id; $this->reason=''; $this->resetValidation(); $this->showVoid=true; }
public function voidPayment(VoidPayment $void): void {
Gate::authorize(Permissions::PAYMENTS_VOID);
abort_if($this->voidPaymentId === null,404);
$void->handle(auth()->user(),$this->voidPaymentId,$this->reason);
$this->showVoid=false; unset($this->payments); \Flux\Flux::toast(variant:'success',text:'Payment voided. Allocated balances have been restored.');
}
};
?>
<x-fees.layout heading="Payment History" subheading="Valid and voided receipts remain visible for audit history.">
<x-fees.filters />
<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><flux:input label="Student or receipt" wire:model.live.debounce.400ms="search" placeholder="Name, admission or receipt number" /><flux:input label="From date" type="date" wire:model.live="dateFrom" /><flux:input label="To date" type="date" wire:model.live="dateTo" /><flux:select label="Method" wire:model.live="method"><option value="">All methods</option>
@foreach(\App\PaymentMethod::cases() as $value)<option value="{{ $value->value }}">{{ $value->label() }}</option>
@endforeach</flux:select></div>
<x-app.panel title="Payments"><x-fees.payment-table :payments="$this->payments" /></x-app.panel>
<flux:modal wire:model="showVoid" class="max-w-lg"><form wire:submit="voidPayment" class="grid gap-4"><flux:heading size="lg">Void Payment</flux:heading><flux:text>The receipt remains in history. Its allocations will no longer reduce the student's debt.</flux:text><flux:textarea label="Reason for voiding" wire:model="reason" required maxlength="500" /><flux:button variant="danger" type="submit" wire:loading.attr="disabled" wire:confirm="Void this payment and restore the invoice balance?">Void Payment</flux:button></form></flux:modal>
</x-fees.layout>
