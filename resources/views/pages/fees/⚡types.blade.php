<?php
use App\Models\FeeType;
use App\Support\Authorization\Permissions;
use App\Support\Fees\FeeLedger;
use App\Support\Settings\SystemSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
return new #[Title('Fee Types')] class extends Component {
use WithPagination;
public string $search = '';
#[Locked] public ?int $editingId = null;
public string $name = '';
public string $code = '';
public string $description = '';
public bool $isActive = true;
public bool $showForm = false;
public function boot(): void { Gate::authorize(Permissions::FEE_TYPES_MANAGE); }
public function updatedSearch(): void { $this->resetPage(); }
/** @return \Illuminate\Pagination\LengthAwarePaginator<int, \App\Models\FeeType> */
#[Computed] public function types(): \Illuminate\Pagination\LengthAwarePaginator { return FeeType::query()->when(trim($this->search) !== '',fn($q)=>$q->where(fn($q)=>$q->where('name','like','%'.trim($this->search).'%')->orWhere('code','like','%'.trim($this->search).'%')))->orderBy('name')->paginate(app(SystemSettings::class)->recordsPerPage()); }
public function create(): void { $this->reset('editingId','name','code','description','isActive'); $this->resetValidation(); $this->showForm=true; }
public function edit(int $id): void { $type=FeeType::query()->findOrFail($id); $this->editingId=$id; $this->name=$type->name; $this->code=$type->code; $this->description=$type->description ?? ''; $this->isActive=$type->is_active; $this->resetValidation(); $this->showForm=true; }
public function save(): void {
Gate::authorize(Permissions::FEE_TYPES_MANAGE);
$this->name=trim($this->name); $this->code=strtoupper(trim($this->code));
$values=$this->validate(['name'=>['required','string','max:100'],'code'=>['required','regex:/^[A-Z0-9_-]+$/D','max:30',Rule::unique('fee_types','code')->ignore($this->editingId)],'description'=>['nullable','string','max:2000'],'isActive'=>['boolean']]);
DB::transaction(function() use($values): void {
$type=$this->editingId === null ? new FeeType : FeeType::query()->lockForUpdate()->findOrFail($this->editingId);
$type->fill(['name'=>$values['name'],'code'=>$values['code'],'description'=>$values['description'] ?: null,'is_active'=>$values['isActive']])->save();
FeeLedger::audit(auth()->user(),'type.saved','fee_type',$type->id);
});
$this->showForm=false; unset($this->types); \Flux\Flux::toast(variant:'success',text:'Fee type saved.');
}
public function delete(int $id): void {
Gate::authorize(Permissions::FEE_TYPES_MANAGE);
DB::transaction(function() use($id): void {
$type=FeeType::query()->lockForUpdate()->findOrFail($id);
if($type->structures()->exists() || $type->invoiceItems()->exists()) { $this->addError('delete','This fee type is referenced. Deactivate it instead.'); return; }
FeeLedger::audit(auth()->user(),'type.deleted','fee_type',$type->id); $type->delete();
});
unset($this->types);
}
};
?>
<x-fees.layout heading="Fee Types" subheading="Deactivate used categories to preserve billing history.">
<div class="flex flex-wrap gap-3"><flux:input class="flex-1" label="Search fee types" wire:model.live.debounce.400ms="search" /><flux:button class="self-end" variant="primary" wire:click="create">Add Fee Type</flux:button></div>
<flux:error name="delete" />
<x-app.panel title="Fee categories"><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-4">Name</th><th class="p-4">Code</th><th class="p-4">Status</th><th class="p-4">Actions</th></tr></thead><tbody>
@forelse($this->types as $type)<tr wire:key="type-{{ $type->id }}"><td class="p-4">{{ $type->name }}<p class="text-xs text-zinc-500">{{ $type->description }}</p></td><td class="p-4">{{ $type->code }}</td><td class="p-4">{{ $type->is_active ? 'Active' : 'Inactive' }}</td><td class="p-4"><div class="flex gap-2"><flux:button size="sm" wire:click="edit({{ $type->id }})">Edit</flux:button><flux:button size="sm" variant="danger" wire:click="delete({{ $type->id }})" wire:confirm="Delete this unused fee category?" wire:loading.attr="disabled">Delete</flux:button></div></td></tr>
@empty<tr><td colspan="4" class="p-8 text-center">No fee types configured. Add a category to begin.</td></tr>
@endforelse</tbody></table></div><div class="p-4">{{ $this->types->links() }}</div></x-app.panel>
<flux:modal wire:model="showForm" class="max-w-lg"><form wire:submit="save" class="grid gap-4"><flux:heading size="lg">{{ $editingId ? 'Edit Fee Type' : 'Add Fee Type' }}</flux:heading><flux:input label="Name" wire:model="name" required /><flux:input label="Code" wire:model="code" required /><flux:textarea label="Description" wire:model="description" /><flux:switch label="Active" wire:model="isActive" /><flux:button variant="primary" type="submit" wire:loading.attr="disabled">Save Fee Type</flux:button></form></flux:modal>
</x-fees.layout>
