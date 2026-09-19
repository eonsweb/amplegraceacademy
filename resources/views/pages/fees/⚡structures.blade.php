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

use App\Actions\Fees\SaveFeeStructures;
use App\Models\FeeStructure;
use App\Models\FeeType;
use Livewire\Attributes\Locked;
return new #[Title('Fee Structure')] class extends Component {
use FiltersFeeContext, WithPagination;
/** @var array<int, array<int, string|null>> */
public array $amounts = [];
/** @var array<int, array<int, bool>> */
public array $active = [];
/** @var list<string> */
#[Locked] public array $loadedContext = [];
public function boot(): void { Gate::authorize(Permissions::FEES_MANAGE); }
/** @return \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\FeeType> */
#[Computed] public function feeTypes(): \Illuminate\Pagination\LengthAwarePaginator { return FeeType::query()->orderBy('name')->paginate(10); }
/** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\ClassLevel> */
#[Computed] public function gridClasses(): \Illuminate\Database\Eloquent\Collection { return $this->classes()->when($this->classLevelId !== '',fn($classes)=>$classes->where('id',(int)$this->classLevelId)); }
public function loadGrid(): void {
$this->validate(['academicYearId'=>['required','exists:academic_years,id'],'termId'=>['required',\Illuminate\Validation\Rule::exists('terms','id')->where('academic_year_id',(int)$this->academicYearId)]]);
$this->amounts=[]; $this->active=[];
foreach(FeeStructure::query()->where('term_id',$this->termId)->get() as $row) { $this->amounts[$row->fee_type_id][$row->class_level_id]=$row->amount; $this->active[$row->fee_type_id][$row->class_level_id]=$row->is_active; }
$this->loadedContext=[$this->academicYearId,$this->termId];
}
public function save(SaveFeeStructures $save): void {
Gate::authorize(Permissions::FEES_MANAGE);
if($this->loadedContext !== [$this->academicYearId,$this->termId]) { $this->addError('rows','Load the selected year and term before saving.'); return; }
$this->validate(['amounts'=>['array'],'amounts.*'=>['array'],'amounts.*.*'=>['nullable','string'],'active'=>['array'],'active.*'=>['array'],'active.*.*'=>['boolean']]);
$rows=[];
foreach($this->amounts as $feeId=>$classes) { foreach($classes as $classId=>$amount) { if($amount !== '' && $amount !== null) { $rows[]=['fee_type_id'=>$feeId,'class_level_id'=>$classId,'amount'=>$amount,'is_active'=>$this->active[$feeId][$classId] ?? true]; } } }
$save->handle(auth()->user(),(int)$this->academicYearId,(int)$this->termId,$rows);
$this->loadGrid(); \Flux\Flux::toast(variant:'success',text:'Fee structure saved. Existing invoice charges are unchanged.');
}
};
?>
<x-fees.layout heading="Fee Structure" subheading="Enter amounts across classes. Blank cells are ignored; turn Active off to stop future billing. Save before switching periods.">
<x-fees.filters :classes="true" />
<flux:error name="academicYearId" /><flux:error name="termId" />
<div><flux:button wire:click="loadGrid" wire:loading.attr="disabled" wire:confirm="Load this period? Unsaved grid changes will be discarded.">Load Fee Structure</flux:button></div>
@if($loadedContext === [$academicYearId,$termId])
<form wire:submit="save" class="grid gap-4">
<x-app.panel title="Class fee amounts"><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-4">Fee Type</th>
@foreach($this->gridClasses as $class)<th class="min-w-40 p-4" wire:key="head-{{ $class->id }}">{{ $class->name }}</th>
@endforeach</tr></thead><tbody>
@forelse($this->feeTypes as $fee)<tr wire:key="fee-{{ $fee->id }}"><td class="p-4 font-semibold">{{ $fee->name }}
@if(!$fee->is_active)<p class="text-xs text-zinc-500">Category inactive</p>
@endif</td>
@foreach($this->gridClasses as $class)<td class="p-4" wire:key="cell-{{ $fee->id }}-{{ $class->id }}"><div class="grid gap-2"><flux:input type="number" min="0.01" step="0.01" aria-label="{{ $fee->name }} for {{ $class->name }}" wire:model="amounts.{{ $fee->id }}.{{ $class->id }}" /><flux:checkbox label="Active" wire:model="active.{{ $fee->id }}.{{ $class->id }}" /></div></td>
@endforeach</tr>
@empty<tr><td class="p-6">Create fee types before configuring amounts.</td></tr>
@endforelse
</tbody></table></div><div class="p-4">{{ $this->feeTypes->links() }}</div></x-app.panel>
@foreach($errors->all() as $error)<p class="text-sm text-red-600">{{ $error }}</p>
@endforeach
<div><flux:button type="submit" variant="primary" wire:loading.attr="disabled">Save Fee Structure</flux:button></div>
</form>
@endif
</x-fees.layout>
