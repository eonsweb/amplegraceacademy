<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Receipt {{ $payment->receipt_number }}</title>
<style>body{font:14px/1.5 system-ui,sans-serif;color:#18181b;margin:0;background:#f4f4f5}.receipt{max-width:720px;margin:32px auto;padding:32px;background:white}header{display:flex;gap:20px;align-items:center;border-bottom:2px solid #18181b;padding-bottom:16px}img{width:64px;height:auto}h1{font-size:22px;margin:0}h2{font-size:17px}dl{display:grid;grid-template-columns:1fr 1fr;gap:8px}dt{font-weight:600}dd{margin:0}table{border-collapse:collapse;width:100%;margin:24px 0}th,td{padding:10px;text-align:left;border-bottom:1px solid #d4d4d8}.void{border:2px solid #b91c1c;color:#b91c1c;padding:12px;font-weight:bold}.controls{margin-bottom:20px}button{padding:10px 20px;cursor:pointer}@media print{body{background:white}.receipt{margin:0;max-width:none;padding:0}.controls{display:none}@page{size:A4;margin:15mm}}</style>
</head><body><main class="receipt"><div class="controls"><button onclick="window.print()">Print Receipt</button></div><header><img src="{{ $settings->dashboardLogoUrl() }}" alt="School logo"><div><h1>{{ $settings->schoolName() }}</h1><p>Payment receipt</p></div></header>
@if($payment->voided_at)<p class="void">VOID — {{ $payment->void_reason }}<br>Voided {{ $settings->formatDate($payment->voided_at) }}</p>@endif
<h2>{{ $payment->receipt_number }}</h2>
<dl><dt>Payment date</dt><dd>{{ $settings->formatDate($payment->payment_date) }}</dd><dt>Amount paid</dt><dd>{{ $settings->formatMoney($payment->amount) }}</dd><dt>Payment method</dt><dd>{{ $payment->payment_method->label() }}</dd><dt>Reference</dt><dd>{{ $payment->reference ?? '—' }}</dd><dt>Received by</dt><dd>{{ $payment->received_by_name }}</dd></dl>
@foreach($payment->allocations as $allocation)
@php($invoice=$allocation->invoice)
<section><h2>{{ $invoice->student_name }} · {{ $invoice->admission_number }}</h2><p>{{ $invoice->class_name }} — {{ $invoice->academic_year_name }} / {{ $invoice->term_name }}</p>
<table><thead><tr><th>Invoice</th><th>Allocated amount</th><th>Current remaining balance</th></tr></thead><tbody><tr><td>{{ $invoice->invoice_number }}</td><td>{{ $settings->formatMoney($allocation->amount) }}</td><td>{{ $settings->formatMoney($invoice->voided_at ? '0.00' : $balances[$invoice->id]->outstanding) }}</td></tr></tbody></table></section>
@endforeach
@if($payment->notes)<p>{{ $payment->notes }}</p>@endif
<p>Balances shown reflect valid payments at the time of printing.</p>
</main></body></html>
