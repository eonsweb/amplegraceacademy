@props(['invoices'])
@inject('settings', 'App\Support\Settings\SystemSettings')
<div class="overflow-x-auto">
<table class="w-full min-w-3xl text-left text-sm">
<thead class="border-b text-xs uppercase text-zinc-500"><tr>
@foreach(['Invoice / Student','Class / Period','Total','Paid','Outstanding','Status','Actions'] as $heading)<th class="px-4 py-3">{{ $heading }}</th>
@endforeach</tr></thead>
<tbody class="divide-y dark:divide-zinc-800">
@forelse($invoices as $invoice)
<tr wire:key="invoice-{{ $invoice->id }}">
<td class="px-4 py-3"><p class="font-semibold">{{ $invoice->student_name }}</p><p>{{ $invoice->admission_number }}</p><p class="text-xs text-zinc-500">{{ $invoice->invoice_number }}</p></td>
<td class="px-4 py-3">{{ $invoice->class_name }}<p class="text-xs">{{ $invoice->academic_year_name }} / {{ $invoice->term_name }}</p></td>
<td class="px-4 py-3 tabular-nums">{{ $settings->formatMoney($invoice->total) }}</td><td class="px-4 py-3 tabular-nums">{{ $settings->formatMoney($invoice->paid) }}</td><td class="px-4 py-3 tabular-nums">{{ $invoice->voided_at ? $settings->formatMoney('0.00') : $settings->formatMoney($invoice->outstanding) }}</td>
<td class="px-4 py-3"><flux:badge :color="$invoice->status() === \App\InvoiceStatus::Paid ? 'green' : 'zinc'">{{ $invoice->status()->label() }}</flux:badge></td>
<td class="px-4 py-3"><div class="flex gap-2">
@can(\App\Support\Authorization\Permissions::INVOICES_VIEW)<flux:button size="sm" :href="route('fees.invoice', $invoice)" wire:navigate>View</flux:button>
@endcan 
@can(\App\Support\Authorization\Permissions::BALANCES_VIEW)<flux:button size="sm" variant="ghost" :href="route('fees.account', $invoice->student_id)" wire:navigate>Account</flux:button>
@endcan</div></td>
</tr>
@empty<tr><td colspan="7" class="p-8 text-center text-zinc-500">No invoices match these filters.</td></tr>
@endforelse
</tbody></table>
</div>
@if($invoices instanceof \Illuminate\Contracts\Pagination\Paginator)<div class="p-4">{{ $invoices->links() }}</div>
@endif
