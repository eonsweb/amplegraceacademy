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

use App\Models\Student;
use Livewire\Attributes\Locked;
return new #[Title('Student Financial Account')] class extends Component {
use FiltersFeeContext, WithPagination;
#[Locked] public int $studentId;
public function boot(): void { Gate::authorize(Permissions::BALANCES_VIEW); }
public function mount(Student $student): void { $this->studentId=$student->id; }
public function updated(string $name): void { if(in_array($name,['academicYearId','termId'],true)) { $this->resetPage('paymentsPage'); } }
#[Computed] public function student(): Student { return Student::query()->with(['currentEnrollment.classLevel','currentEnrollment.academicYear'])->findOrFail($this->studentId); }
/** @return array{total: string, paid: string, outstanding: string, owing: int} */
#[Computed] public function totals(): array { return FeeLedger::summary($this->invoiceQuery()->where('student_id',$this->studentId)); }
/** @return \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\Invoice> */
#[Computed] public function invoices(): \Illuminate\Pagination\LengthAwarePaginator { return $this->invoiceQuery()->where('student_id',$this->studentId)->orderByDesc('id')->paginate(app(SystemSettings::class)->recordsPerPage()); }
/** @return \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\Payment> */
#[Computed] public function payments(): \Illuminate\Pagination\LengthAwarePaginator { Gate::authorize(Permissions::PAYMENTS_VIEW); return FeeLedger::payments($this->academicYearId,$this->termId,$this->studentId)->with('student:id,first_name,middle_name,last_name,admission_number')->orderByDesc('payment_date')->orderByDesc('id')->paginate(app(SystemSettings::class)->recordsPerPage(),['*'],'paymentsPage'); }
};
?>
<x-fees.layout heading="Student Financial Account" subheading="All years includes historical invoices and carried-forward debt without billing it again.">
<x-app.panel :title="$this->student->fullName()"><div class="grid gap-2 p-5 sm:grid-cols-3"><p>Admission: {{ $this->student->admission_number }}</p><p>Current class: {{ $this->student->currentEnrollment?->classLevel->name ?? 'Not enrolled' }}</p><p>Academic year: {{ $this->student->currentEnrollment?->academicYear->name ?? '—' }}</p></div></x-app.panel>
<x-fees.filters />
<x-fees.totals :totals="$this->totals" />
<x-app.panel title="Invoice history"><x-fees.invoice-table :invoices="$this->invoices" /></x-app.panel>
@can(Permissions::PAYMENTS_VIEW)<x-app.panel title="Payment history"><x-fees.payment-table :payments="$this->payments" /></x-app.panel>
@endcan
</x-fees.layout>
