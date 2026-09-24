<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $definition['title'] }}</title>
<style>
body{font:13px/1.5 system-ui,sans-serif;color:#18181b;background:white;margin:24px}
header{display:flex;gap:16px;align-items:center}header img{width:64px;height:auto}
h1{font-size:22px;margin:0}h2{font-size:18px}table{border-collapse:collapse;width:100%;margin-top:20px}
th,td{padding:8px;border-bottom:1px solid #d4d4d8;text-align:left;overflow-wrap:anywhere}
thead{display:table-header-group}tr{break-inside:avoid}dl{display:flex;flex-wrap:wrap;gap:12px 24px}dt{font-weight:600}dd{margin:0}
.controls{margin-bottom:20px}button{padding:10px 20px;cursor:pointer}
@media print{.controls{display:none}body{margin:0}@page{size:A4 landscape;margin:12mm}}
</style></head><body><main>
<div class="controls"><button onclick="window.print()">Print report</button></div>
<header><img src="{{ $settings->dashboardLogoUrl() }}" alt="School logo"><div><h1>{{ $settings->schoolName() }}</h1><h2>{{ $definition['title'] }}</h2></div></header>
<p>Generated {{ $generated }} · Currency: {{ $settings->currency() }}</p>
<p>{{ $definition['note'] }}</p>
<dl>@forelse ($filters as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@empty<div>All available records</div>@endforelse</dl>
<dl>@foreach ($summary as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ $value ?? '—' }}</dd></div>@endforeach</dl>
<table><thead><tr>@foreach ($definition['columns'] as $label)<th scope="col">{{ $label }}</th>@endforeach</tr></thead><tbody>
