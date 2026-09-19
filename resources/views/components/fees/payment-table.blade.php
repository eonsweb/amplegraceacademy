@props(['payments'])
@inject('settings', 'App\Support\Settings\SystemSettings')
<div class="overflow-x-auto"><table class="w-full min-w-3xl text-left text-sm">
<thead class="border-b text-xs uppercase text-zinc-500"><tr>
@foreach(['Receipt','Student','Date','Amount','Method','Received by','Status','Actions'] as $heading)<th class="px-4 py-3">{{ $heading }}</th>
@endforeach</tr></thead>
<tbody class="divide-y dark:divide-zinc-800">
@forelse($payments as $payment)
<tr wire:key="payment-{{ $payment->id }}"><td class="px-4 py-3 text-xs">{{ $payment->receipt_number }}</td><td class="px-4 py-3">{{ $payment->student->fullName() }}<p class="text-xs">{{ $payment->student->admission_number }}</p></td><td class="px-4 py-3">{{ $settings->formatDate($payment->payment_date) }}</td><td class="px-4 py-3 tabular-nums">{{ $settings->formatMoney($payment->amount) }}</td><td class="px-4 py-3">{{ $payment->payment_method->label() }}</td><td class="px-4 py-3">{{ $payment->received_by_name }}</td><td class="px-4 py-3">{{ $payment->voided_at ? 'Void' : 'Valid' }}
@if($payment->voided_at)<p class="text-xs">{{ $payment->void_reason }}</p>
@endif</td><td class="px-4 py-3"><div class="flex gap-2">
@can(\App\Support\Authorization\Permissions::RECEIPTS_PRINT)<flux:button size="sm" :href="route('fees.receipt', $payment)" target="_blank">Print Receipt</flux:button>
@endcan
@if(method_exists($this, 'openVoid'))
@can(\App\Support\Authorization\Permissions::PAYMENTS_VOID)
@if(!$payment->voided_at)<flux:button size="sm" variant="danger" wire:click="openVoid({{ $payment->id }})">Void Payment</flux:button>
@endif
@endcan
@endif
</div></td></tr>
@empty<tr><td colspan="8" class="p-8 text-center text-zinc-500">No payments match these filters.</td></tr>
@endforelse
</tbody></table></div>
@if($payments instanceof \Illuminate\Contracts\Pagination\Paginator)<div class="p-4">{{ $payments->links() }}</div>
@endif
