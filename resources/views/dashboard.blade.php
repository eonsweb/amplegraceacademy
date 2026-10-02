<x-layouts::app :title="__('Dashboard')">
    @inject('systemSettings', 'App\Support\Settings\SystemSettings')
    <div class="grid gap-5">
        <header class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">Dashboard</h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Welcome back, {{ auth()->user()->name }}! Here's what's happening today.</p>
            </div>
            <div class="inline-flex h-10 w-fit items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 text-sm font-medium text-zinc-600 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
                <flux:icon name="calendar-days" class="size-4.5 text-zinc-600 dark:text-zinc-300" aria-hidden="true" />
                <time datetime="{{ $today->toDateString() }}">{{ $today->format('l, F j, Y') }}</time>
            </div>
        </header>
        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $academicYear ?? 'No current academic year configured' }} · {{ $academicTerm ?? 'No current term configured' }}</p>
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-5" aria-label="Dashboard summary">
            @if ($students !== null)
                <x-app.stat-card icon="user-group" label="Total Pupils" :value="number_format($students['total'])" :trend="$students['new'].' enrolled this month'" trendTone="neutral" />
            @endif
            @if ($teachers !== null)
                <x-app.stat-card icon="user-group" label="Total Teachers" :value="number_format($teachers['total'])" :trend="$teachers['new'].' joined this month'" trendTone="neutral" />
            @endif
            @if ($classes !== null)
                <x-app.stat-card icon="academic-cap" label="Total Classes" :value="number_format($classes)" />
            @endif
            @if ($finance !== null)
                <x-app.stat-card icon="wallet" label="Fee Collection (This Month)" :value="$systemSettings->formatMoney($finance['current'])" :trend="$finance['trend']" trendTone="neutral" />
                <x-app.stat-card icon="wallet" label="Outstanding Fees" :value="$finance['outstanding'] === null ? 'Not configured' : $systemSettings->formatMoney($finance['outstanding'])" trend="Current academic year" trendTone="neutral" />
                <x-app.stat-card icon="wallet" label="Canteen Collection (Today)" value="Not available" />
            @endif
            @if ($attendance !== null)
                <x-app.stat-card icon="user-group" label="Attendance Today" :value="$attendance['percentage'] === null ? 'Not recorded' : $attendance['percentage'].'%'" trend="Present + late / recorded" trendTone="neutral" />
            @endif
        </section>

        <div class="grid gap-4 xl:grid-cols-2 2xl:grid-cols-12">
            @if ($finance !== null)
            <x-app.panel title="Fee Collection Overview" class="2xl:col-span-5">
                <x-slot:action>
                    <form action="{{ route('dashboard') }}" method="GET" class="flex items-center gap-2">
                        <label for="fee-period" class="sr-only">Collection period</label>
                        <select id="fee-period" name="period" class="rounded-lg border-zinc-200 bg-white text-xs dark:border-zinc-700 dark:bg-zinc-900">
                            <option value="year" @selected($period === 'year')>This Year</option>
                            <option value="previous-year" @selected($period === 'previous-year')>Previous Year</option>
                        </select>
                        <button type="submit" class="rounded-lg border border-zinc-200 px-2 py-1 text-xs dark:border-zinc-700">Apply</button>
                    </form>
                </x-slot:action>
                <div class="px-3 pb-3 pt-4 sm:px-4">
                    <p class="mb-2 text-xs text-zinc-500">Calendar year {{ $finance['chartYear'] }} · All academic periods · {{ $systemSettings->currency() }}</p>
                    @if (! $finance['hasCollections'])
                        <p class="text-sm text-zinc-500">No collections in this period.</p>
                    @endif
                    <svg class="h-auto w-full" viewBox="0 0 600 290" role="img" aria-labelledby="fee-chart-title fee-chart-description">
                        <title id="fee-chart-title">Fee collection in {{ $finance['chartYear'] }}</title>
                        <desc id="fee-chart-description">Valid payments grouped by payment month. Months without collections are zero.</desc>
                        <defs><linearGradient id="fee-area" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="#760016" stop-opacity="0.22" /><stop offset="100%" stop-color="#760016" stop-opacity="0.02" /></linearGradient></defs>
                        <g fill="none" stroke="#e4e4e7" stroke-width="1"><path d="M58 20H575 M58 62H575 M58 104H575 M58 146H575 M58 188H575 M58 230H575" /></g>
                        <g fill="#71717a" font-size="11" font-family="system-ui, sans-serif">
                            <text x="58" y="14">{{ $systemSettings->formatMoney($finance['maximum']) }}</text><text x="20" y="234">0</text>
                            @foreach ($finance['points'] as $point)
                                <text x="{{ $point['x'] }}" y="270" text-anchor="middle">{{ $point['label'] }}</text>
                            @endforeach
                        </g>
                        <path d="{{ $finance['path'] }} L{{ $finance['points'][array_key_last($finance['points'])]['x'] }} 230 L58 230 Z" fill="url(#fee-area)" />
                        <path d="{{ $finance['path'] }}" fill="none" stroke="#760016" stroke-linecap="round" stroke-linejoin="round" stroke-width="3" />
                        <g fill="#760016" stroke="white" stroke-width="2">
                            @foreach ($finance['points'] as $point)
                                <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="4"><title>{{ $point['label'] }}: {{ $systemSettings->formatMoney($point['amount']) }}</title></circle>
                            @endforeach
                        </g>
                    </svg>
                </div>
            </x-app.panel>
            @endif

            @if ($attendance !== null)
            <x-app.panel title="Student Attendance Overview" class="2xl:col-span-4">
                <x-slot:action><span class="text-xs text-zinc-500">Today</span></x-slot:action>
                <div class="flex min-h-72 flex-col items-center justify-center gap-7 p-5 sm:flex-row sm:gap-8">
                    <div class="grid size-48 shrink-0 place-items-center rounded-full" style="background: {{ $attendance['gradient'] }}" role="img" aria-label="{{ $attendance['records'] }} attendance records today">
                        <div class="grid size-31 place-items-center rounded-full bg-white text-center shadow-inner dark:bg-zinc-900">
                            <p><span class="block text-xl font-bold tracking-tight text-zinc-950 dark:text-white">{{ $attendance['percentage'] === null ? 'Not recorded' : $attendance['percentage'].'%' }}</span><span class="mt-1 block text-xs text-zinc-600 dark:text-zinc-300">Present + late</span></p>
                        </div>
                    </div>
                    <dl class="grid min-w-31 gap-5 text-sm">
                        @foreach ($attendance['segments'] as $segment)
                            <div class="grid grid-cols-[auto_1fr] gap-x-2">
                                <span class="mt-1 size-2.5 rounded-full" style="background: {{ $segment['color'] }}" aria-hidden="true"></span>
                                <div><dt class="font-medium text-zinc-700 dark:text-zinc-200">{{ $segment['label'] }}</dt><dd class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $segment['count'] }} ({{ $segment['percentage'] }}%)</dd></div>
                            </div>
                        @endforeach
                    </dl>
                </div>
                <p class="px-5 pb-4 text-xs text-zinc-500">Recorded days only; unmarked pupils are not counted as absent. Excused records remain in the denominator.</p>
            </x-app.panel>
            @endif

            <x-app.panel title="Recent Notices" class="xl:col-span-2 2xl:col-span-3">
                <p class="p-5 text-sm text-zinc-500 dark:text-zinc-400">No notices available.</p>
            </x-app.panel>
        </div>

        <div class="grid gap-4 2xl:grid-cols-12">
            @if ($recentPayments !== null)
            <x-app.panel title="Recent Payments" class="2xl:col-span-9">
                <x-slot:action><a href="{{ route('fees.payments') }}" wire:navigate class="rounded-lg border border-zinc-200 px-3 py-2 text-xs dark:border-zinc-700">View all</a></x-slot:action>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[800px] text-left text-sm">
                        <caption class="sr-only">Five most recent valid student fee payments</caption>
                        <thead class="border-b border-zinc-200 bg-zinc-50/70 text-xs font-semibold text-zinc-600 dark:border-zinc-800 dark:bg-zinc-800/70 dark:text-zinc-300">
                            <tr><th scope="col" class="px-4 py-3">Receipt No.</th><th scope="col" class="px-4 py-3">Student</th><th scope="col" class="px-4 py-3">Class</th><th scope="col" class="px-4 py-3">Amount</th><th scope="col" class="px-4 py-3">Payment Method</th><th scope="col" class="px-4 py-3">Date</th><th scope="col" class="px-4 py-3">Status</th></tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 text-xs text-zinc-600 dark:divide-zinc-800 dark:text-zinc-300 sm:text-sm">
                            @forelse ($recentPayments as $payment)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-3">{{ $payment->receipt_number }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ $payment->allocations->first()?->invoice?->student_name ?? $payment->student->fullName() }}</td>
                                    <td class="px-4 py-3">{{ $payment->allocations->pluck('invoice.class_name')->filter()->unique()->implode(', ') ?: '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ $systemSettings->formatMoney($payment->amount) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ $payment->payment_method->label() }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ $systemSettings->formatDate($payment->payment_date->shiftTimezone($systemSettings->timezone())) }}</td>
                                    <td class="px-4 py-3"><x-app.status-badge status="Valid" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="p-8 text-center">No payments recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-app.panel>
            @endif
            <x-app.panel title="Upcoming Events" class="2xl:col-span-3">
                <p class="p-5 text-sm text-zinc-500 dark:text-zinc-400">No upcoming events available.</p>
            </x-app.panel>
        </div>
        <footer class="py-3 text-center text-sm font-medium text-zinc-800 dark:text-zinc-200">
            Shaping Futures, Transforming <span class="font-serif text-xl italic text-brand-700">Lives</span>
        </footer>
    </div>
</x-layouts::app>
