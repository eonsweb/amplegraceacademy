<?php
use App\Actions\Fees\VoidInvoice;
use App\Models\Invoice;
use App\Support\Authorization\Permissions;
use App\Support\Fees\FeeLedger;
use App\Support\Settings\SystemSettings;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
return new #[Title('Student Invoice')] class extends Component {
use \Livewire\WithPagination;
#[Locked] public int $invoiceId;
public string $reason='';
public bool $showVoid=false;
public function boot(): void { Gate::authorize(Permissions::INVOICES_VIEW); }
public function mount(Invoice $invoice): void { Gate::authorize('view',$invoice); $this->invoiceId=$invoice->id; }
#[Computed] public function invoice(): Invoice { return FeeLedger::invoices()->with('items')->findOrFail($this->invoiceId); }
/** @return \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\Payment> */
#[Computed] public function payments(): \Illuminate\Pagination\LengthAwarePaginator { Gate::authorize(Permissions::PAYMENTS_VIEW); return \App\Models\Payment::query()->whereHas('allocations',fn($q)=>$q->where('invoice_id',$this->invoiceId))->with('student:id,first_name,middle_name,last_name,admission_number')->orderByDesc('id')->paginate(10); }
public function voidInvoice(VoidInvoice $void): void { $void->handle(auth()->user(),$this->invoiceId,$this->reason); $this->showVoid=false; unset($this->invoice); \Flux\Flux::toast(variant:'success',text:'Invoice voided and preserved in history.'); }
};
?>
<x-fees.layout heading="Student Invoice">
@php($invoice=$this->invoice)
<x-app.panel :title="$invoice->invoice_number"><div class="grid gap-4 p-5"><div class="flex flex-wrap justify-between gap-3"><div><p class="text-lg font-semibold">{{ $invoice->student_name }}</p><p>{{ $invoice->admission_number }} · {{ $invoice->class_name }}</p><p>{{ $invoice->academic_year_name }} / {{ $invoice->term_name }}</p></div><div><flux:badge>{{ $invoice->status()->label() }}</flux:badge><p>Issued {{ app(SystemSettings::class)->formatDate($invoice->issue_date) }}</p><p>Due {{ app(SystemSettings::class)->formatDate($invoice->due_date) }}</p></div></div>
<div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-3">Fee</th><th class="p-3">Quantity</th><th class="p-3">Unit amount</th><th class="p-3">Amount</th></tr></thead><tbody>
@foreach($invoice->items as $item)<tr wire:key="item-{{ $item->id }}"><td class="p-3">{{ $item->description }}</td><td class="p-3">{{ $item->quantity }}</td><td class="p-3">{{ app(SystemSettings::class)->formatMoney($item->unit_amount) }}</td><td class="p-3">{{ app(SystemSettings::class)->formatMoney($item->amount) }}</td></tr>
@endforeach</tbody></table></div>
@if($invoice->voided_at)<flux:callout heading="Invoice voided" :text="$invoice->void_reason" />
@endif
@if($invoice->notes)<p>{{ $invoice->notes }}</p>
@endif
<div class="flex flex-wrap gap-3">
@can(Permissions::BALANCES_VIEW)<flux:button :href="route('fees.account',$invoice->student_id)" wire:navigate>Student Account</flux:button>
@endcan
@can(Permissions::INVOICES_VOID)
@if(!$invoice->voided_at)<flux:button variant="danger" wire:click="$set('showVoid',true)">Void Invoice</flux:button>
@endif
@endcan</div>
</div></x-app.panel>
<x-fees.totals :totals="['total'=>$invoice->total,'paid'=>$invoice->paid,'outstanding'=>$invoice->voided_at ? '0.00' : $invoice->outstanding]" />
@can(Permissions::PAYMENTS_VIEW)<x-app.panel title="Payments against this invoice"><x-fees.payment-table :payments="$this->payments" /></x-app.panel>
@endcan
<flux:modal wire:model="showVoid" class="max-w-lg"><form wire:submit="voidInvoice" class="grid gap-4"><flux:heading size="lg">Void Invoice</flux:heading><flux:text>Valid payments must be voided first. This invoice remains in history and cannot be generated again.</flux:text><flux:textarea label="Reason" wire:model="reason" required /><flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:confirm="Void this invoice? It will be removed from outstanding balances.">Void Invoice</flux:button></form></flux:modal>
</x-fees.layout>
