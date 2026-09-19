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

return new #[Title('Fees Overview')] class extends Component {
use FiltersFeeContext, WithPagination;
public function boot(): void { Gate::authorize(Permissions::FEES_VIEW); }
/** @return array{total: string, paid: string, outstanding: string, owing: int} */
#[Computed] public function totals(): array { return FeeLedger::summary($this->invoiceQuery()); }
#[Computed] public function paymentsToday(): string { return (string) FeeLedger::payments($this->academicYearId,$this->termId)->whereNull('voided_at')->where('payment_date',now(app(SystemSettings::class)->timezone())->toDateString())->sum('amount'); }
/** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Payment> */
#[Computed] public function recentPayments(): \Illuminate\Database\Eloquent\Collection { Gate::authorize(Permissions::PAYMENTS_VIEW); return FeeLedger::payments($this->academicYearId,$this->termId)->with('student:id,first_name,middle_name,last_name,admission_number')->orderByDesc('id')->limit(5)->get(); }
/** @return \Illuminate\Pagination\LengthAwarePaginator<int, \stdClass> */
#[Computed] public function classSummary(): \Illuminate\Pagination\LengthAwarePaginator { Gate::authorize(Permissions::FINANCIAL_REPORTS_VIEW); return \Illuminate\Support\Facades\DB::query()->fromSub($this->invoiceQuery()->whereNull('voided_at')->toBase(),'ledger')->select('class_level_id','class_name')->selectRaw('SUM(total) AS total, SUM(paid) AS paid, SUM(outstanding) AS outstanding')->groupBy('class_level_id','class_name')->orderByDesc('outstanding')->paginate(10); }
};
?>
<x-fees.layout heading="Overview" subheading="All years includes balances carried forward from previous terms.">
    <x-fees.filters />
    <x-fees.totals :totals="$this->totals" />
    <div class="grid gap-3 sm:grid-cols-2"><x-app.panel title="Students owing fees"><p class="p-5 text-2xl font-semibold">{{ $this->totals['owing'] }}</p></x-app.panel><x-app.panel title="Payments today"><p class="p-5 text-2xl font-semibold">{{ app(SystemSettings::class)->formatMoney($this->paymentsToday) }}</p></x-app.panel></div>
    
@can(Permissions::FINANCIAL_REPORTS_VIEW)
    <x-app.panel title="Class balance summary"><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-4">Class</th><th class="p-4">Invoiced</th><th class="p-4">Collected</th><th class="p-4">Outstanding</th></tr></thead><tbody>
@forelse($this->classSummary as $row)<tr wire:key="summary-{{ $row->class_level_id }}-{{ $loop->index }}"><td class="p-4">{{ $row->class_name }}</td>
@foreach(['total','paid','outstanding'] as $key)<td class="p-4 tabular-nums">{{ app(SystemSettings::class)->formatMoney($row->$key) }}</td>
@endforeach</tr>
@empty<tr><td colspan="4" class="p-6 text-center">No billed classes yet.</td></tr>
@endforelse</tbody></table></div><div class="p-4">{{ $this->classSummary->links() }}</div></x-app.panel>
    
@endcan
    
@can(Permissions::PAYMENTS_VIEW)<x-app.panel title="Recent payments"><x-fees.payment-table :payments="$this->recentPayments" /></x-app.panel>
@endcan
</x-fees.layout>
