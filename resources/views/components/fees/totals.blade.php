@props(['totals'])
@inject('settings', 'App\Support\Settings\SystemSettings')
<div class="grid gap-3 sm:grid-cols-3">
@foreach(['total'=>'Total invoiced','paid'=>'Total collected','outstanding'=>'Outstanding balance'] as $key=>$label)
<x-app.panel :title="$label"><p class="p-5 text-2xl font-semibold tabular-nums">{{ $settings->formatMoney($totals[$key]) }}</p></x-app.panel>
@endforeach
</div>
