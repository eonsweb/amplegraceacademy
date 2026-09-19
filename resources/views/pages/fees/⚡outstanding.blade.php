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

return new #[Title('Outstanding Fees')] class extends Component {
use FiltersFeeContext, WithPagination;
public function boot(): void { Gate::authorize(Permissions::BALANCES_VIEW); }
/** @return \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\Invoice> */
#[Computed] public function invoices(): \Illuminate\Pagination\LengthAwarePaginator { return $this->invoiceQuery()->whereNull('voided_at')->where('outstanding','>',0)->orderByDesc('outstanding')->orderBy('id')->paginate(app(SystemSettings::class)->recordsPerPage()); }
};
?>
<x-fees.layout heading="Outstanding Fees" subheading="Unpaid and partially paid invoices, largest balance first. All years includes prior-term debt.">
<x-fees.filters :classes="true" :search="true" />
<x-app.panel title="Outstanding invoices"><x-fees.invoice-table :invoices="$this->invoices" /></x-app.panel>
</x-fees.layout>
