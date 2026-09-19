@props(['heading', 'subheading' => ''])
@php
use App\Support\Authorization\Permissions;
$links = [
['fees.overview', Permissions::FEES_VIEW, 'Overview'],
['fees.types', Permissions::FEE_TYPES_MANAGE, 'Fee Types'],
['fees.structures', Permissions::FEES_MANAGE, 'Fee Structure'],
['fees.invoices', Permissions::INVOICES_VIEW, 'Invoices'],
['fees.record-payment', Permissions::PAYMENTS_RECORD, 'Record Payment'],
['fees.payments', Permissions::PAYMENTS_VIEW, 'Payment History'],
['fees.outstanding', Permissions::BALANCES_VIEW, 'Outstanding Fees'],
];
@endphp
<section class="grid w-full gap-5">
    <div><flux:heading size="xl" level="1">Fees &amp; Payments</flux:heading><flux:text>Billing, receipts and student accounts</flux:text></div>
    <nav class="flex flex-wrap gap-2 print:hidden" aria-label="Fees and payments">
        
@foreach($links as [$routeName, $permission, $label])
            
@can($permission)<flux:button size="sm" :variant="request()->routeIs($routeName) ? 'primary' : 'ghost'" :href="route($routeName)" wire:navigate>{{ $label }}</flux:button>
@endcan
        
@endforeach
    </nav>
    <div><flux:heading size="lg">{{ $heading }}</flux:heading>
@if($subheading)<flux:text>{{ $subheading }}</flux:text>
@endif</div>
    {{ $slot }}
</section>
