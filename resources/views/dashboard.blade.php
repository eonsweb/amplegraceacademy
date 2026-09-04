<x-layouts::app :title="__('Dashboard')">
    {{-- Presentation-only placeholders; future modules will replace these values with read models. --}}
    <div class="grid gap-5">
        <header class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-950">Dashboard</h1>
                <p class="mt-1 text-sm text-zinc-500">Welcome back, {{ auth()->user()->name }}! Here's what's happening today.</p>
            </div>
            <div class="inline-flex h-10 w-fit items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 text-sm font-medium text-zinc-600 shadow-sm">
                <flux:icon name="calendar-days" class="size-4.5 text-zinc-600" aria-hidden="true" />
                <time datetime="2025-05-22">Thursday, May 22, 2025</time>
            </div>
        </header>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5" aria-label="Dashboard summary">
            <x-app.stat-card icon="user-group" label="Total Students" value="1,248" trend="12 this month" />
            <x-app.stat-card icon="academic-cap" label="Total Teachers" value="86" trend="2 this month" />
            <x-app.stat-card icon="book-open" label="Total Classes" value="48" trend="No change" trend-tone="neutral" />
            <x-app.stat-card icon="wallet" label="Fee Collection" value="₦4,250,000" trend="18% this month" />
            <x-app.stat-card icon="calendar-days" label="Attendance Today" value="92.6%" trend="3.4% vs yesterday" />
        </section>

        <div class="grid gap-4 xl:grid-cols-2 2xl:grid-cols-12">
            <x-app.panel title="Fee Collection Overview" class="2xl:col-span-5">
                <x-slot:action>
                    <button type="button" class="inline-flex h-8 items-center gap-2 rounded-lg border border-zinc-200 px-3 text-xs font-medium text-zinc-600 hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700">
                        This Year
                        <flux:icon name="chevron-down" class="size-3.5" aria-hidden="true" />
                    </button>
                </x-slot:action>

                <div class="px-3 pb-3 pt-4 sm:px-4">
                    <svg class="h-auto w-full" viewBox="0 0 600 290" role="img" aria-labelledby="fee-chart-title fee-chart-description">
                        <title id="fee-chart-title">Fee collection from January to June 2025</title>
                        <desc id="fee-chart-description">Collections rise from 2 million naira in January to 4.6 million naira in June, with a dip in April.</desc>
                        <defs>
                            <linearGradient id="fee-area" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stop-color="#760016" stop-opacity="0.22" />
                                <stop offset="100%" stop-color="#760016" stop-opacity="0.02" />
                            </linearGradient>
                        </defs>

                        <g fill="none" stroke="#e4e4e7" stroke-width="1">
                            <path d="M58 20H575" />
                            <path d="M58 62H575" />
                            <path d="M58 104H575" />
                            <path d="M58 146H575" />
                            <path d="M58 188H575" />
                            <path d="M58 230H575" />
                        </g>
                        <g fill="#71717a" font-size="12" font-family="system-ui, sans-serif">
                            <text x="8" y="24">₦5M</text>
                            <text x="8" y="66">₦4M</text>
                            <text x="8" y="108">₦3M</text>
                            <text x="8" y="150">₦2M</text>
                            <text x="8" y="192">₦1M</text>
                            <text x="20" y="234">₦0</text>
                            <text x="58" y="270" text-anchor="middle">Jan</text>
                            <text x="161" y="270" text-anchor="middle">Feb</text>
                            <text x="264" y="270" text-anchor="middle">Mar</text>
                            <text x="367" y="270" text-anchor="middle">Apr</text>
                            <text x="470" y="270" text-anchor="middle">May</text>
                            <text x="573" y="270" text-anchor="middle">Jun</text>
                        </g>

                        <path d="M58 146 C100 137 122 126 161 121 S225 83 264 87 S326 122 367 116 S430 88 470 79 S535 50 573 37 L573 230 L58 230 Z" fill="url(#fee-area)" />
                        <path d="M58 146 C100 137 122 126 161 121 S225 83 264 87 S326 122 367 116 S430 88 470 79 S535 50 573 37" fill="none" stroke="#760016" stroke-linecap="round" stroke-linejoin="round" stroke-width="3" />
                        <g fill="#760016" stroke="white" stroke-width="2">
                            <circle cx="58" cy="146" r="5" />
                            <circle cx="161" cy="121" r="5" />
                            <circle cx="264" cy="87" r="5" />
                            <circle cx="367" cy="116" r="5" />
                            <circle cx="470" cy="79" r="5" />
                            <circle cx="573" cy="37" r="5" />
                        </g>
                    </svg>
                </div>
            </x-app.panel>

            <x-app.panel title="Student Attendance Overview" class="2xl:col-span-4">
                <x-slot:action>
                    <button type="button" class="inline-flex h-8 items-center gap-2 rounded-lg border border-zinc-200 px-3 text-xs font-medium text-zinc-600 hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700">
                        Today
                        <flux:icon name="chevron-down" class="size-3.5" aria-hidden="true" />
                    </button>
                </x-slot:action>

                <div class="flex min-h-72 flex-col items-center justify-center gap-7 p-5 sm:flex-row sm:gap-8">
                    <div
                        class="grid size-48 shrink-0 place-items-center rounded-full"
                        style="background: conic-gradient(var(--color-brand-700) 0 92.6%, #d4d4d8 92.6% 98.4%, #fbbf24 98.4% 100%)"
                        role="img"
                        aria-label="Attendance: 92.6 percent present, 5.8 percent absent, and 1.6 percent late"
                    >
                        <div class="grid size-31 place-items-center rounded-full bg-white text-center shadow-inner">
                            <p>
                                <span class="block text-2xl font-bold tracking-tight text-zinc-950">92.6%</span>
                                <span class="mt-1 block text-sm text-zinc-600">Present</span>
                            </p>
                        </div>
                    </div>

                    <dl class="grid min-w-31 gap-5 text-sm">
                        <div class="grid grid-cols-[auto_1fr] gap-x-2">
                            <span class="mt-1 size-2.5 rounded-full bg-brand-700" aria-hidden="true"></span>
                            <div>
                                <dt class="font-medium text-zinc-700">Present</dt>
                                <dd class="mt-1 text-xs text-zinc-500">1,156 (92.6%)</dd>
                            </div>
                        </div>
                        <div class="grid grid-cols-[auto_1fr] gap-x-2">
                            <span class="mt-1 size-2.5 rounded-full bg-zinc-300" aria-hidden="true"></span>
                            <div>
                                <dt class="font-medium text-zinc-700">Absent</dt>
                                <dd class="mt-1 text-xs text-zinc-500">72 (5.8%)</dd>
                            </div>
                        </div>
                        <div class="grid grid-cols-[auto_1fr] gap-x-2">
                            <span class="mt-1 size-2.5 rounded-full bg-amber-400" aria-hidden="true"></span>
                            <div>
                                <dt class="font-medium text-zinc-700">Late</dt>
                                <dd class="mt-1 text-xs text-zinc-500">20 (1.6%)</dd>
                            </div>
                        </div>
                    </dl>
                </div>
            </x-app.panel>

            <x-app.panel title="Recent Notices" class="xl:col-span-2 2xl:col-span-3">
                <x-slot:action>
                    <button type="button" class="h-8 rounded-lg border border-zinc-200 px-3 text-xs font-medium text-zinc-600 hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700">View all</button>
                </x-slot:action>
                <div class="divide-y divide-zinc-200 px-4 py-4">
                    <x-app.notice-item icon="megaphone" title="Midterm Exams Schedule" description="Midterm exams will commence from June 2nd, 2025." date="May 21, 2025 • 10:30 AM" />
                    <x-app.notice-item icon="calendar-days" title="Parent-Teacher Meeting" description="PTM scheduled for Saturday, May 31st, 2025." date="May 20, 2025 • 02:15 PM" />
                    <x-app.notice-item icon="information-circle" title="School Closure" description="School will be closed on Monday, May 26th, 2025 for Public Holiday." date="May 19, 2025 • 08:45 AM" />
                </div>
            </x-app.panel>
        </div>

        <div class="grid gap-4 2xl:grid-cols-12">
            <x-app.panel title="Recent Payments" class="2xl:col-span-9">
                <x-slot:action>
                    <button type="button" class="h-8 rounded-lg border border-zinc-200 px-3 text-xs font-medium text-zinc-600 hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700">View all</button>
                </x-slot:action>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[800px] text-left text-sm">
                        <caption class="sr-only">Five most recent student fee payments</caption>
                        <thead class="border-b border-zinc-200 bg-zinc-50/70 text-xs font-semibold text-zinc-600">
                            <tr>
                                <th scope="col" class="px-4 py-3">Receipt No.</th>
                                <th scope="col" class="px-4 py-3">Student</th>
                                <th scope="col" class="px-4 py-3">Class</th>
                                <th scope="col" class="px-4 py-3">Amount</th>
                                <th scope="col" class="px-4 py-3">Payment Method</th>
                                <th scope="col" class="px-4 py-3">Date</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 text-xs text-zinc-600 sm:text-sm">
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3">RCP-2025-0056</td><td class="whitespace-nowrap px-4 py-3">John Doe</td><td class="px-4 py-3">SS 2A</td><td class="whitespace-nowrap px-4 py-3">₦75,000</td><td class="whitespace-nowrap px-4 py-3">Bank Transfer</td><td class="whitespace-nowrap px-4 py-3">May 22, 2025</td><td class="px-4 py-3"><x-app.status-badge status="Paid" /></td>
                            </tr>
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3">RCP-2025-0055</td><td class="whitespace-nowrap px-4 py-3">Jane Smith</td><td class="px-4 py-3">JSS 3B</td><td class="whitespace-nowrap px-4 py-3">₦65,000</td><td class="px-4 py-3">POS</td><td class="whitespace-nowrap px-4 py-3">May 22, 2025</td><td class="px-4 py-3"><x-app.status-badge status="Paid" /></td>
                            </tr>
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3">RCP-2025-0054</td><td class="whitespace-nowrap px-4 py-3">Michael Brown</td><td class="px-4 py-3">SS 1C</td><td class="whitespace-nowrap px-4 py-3">₦75,000</td><td class="px-4 py-3">Cash</td><td class="whitespace-nowrap px-4 py-3">May 21, 2025</td><td class="px-4 py-3"><x-app.status-badge status="Paid" /></td>
                            </tr>
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3">RCP-2025-0053</td><td class="whitespace-nowrap px-4 py-3">Emmanuel Daniel</td><td class="px-4 py-3">JSS 2A</td><td class="whitespace-nowrap px-4 py-3">₦62,000</td><td class="whitespace-nowrap px-4 py-3">Bank Transfer</td><td class="whitespace-nowrap px-4 py-3">May 21, 2025</td><td class="px-4 py-3"><x-app.status-badge status="Paid" /></td>
                            </tr>
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3">RCP-2025-0052</td><td class="whitespace-nowrap px-4 py-3">Sarah Williams</td><td class="px-4 py-3">SS 3B</td><td class="whitespace-nowrap px-4 py-3">₦75,000</td><td class="px-4 py-3">POS</td><td class="whitespace-nowrap px-4 py-3">May 20, 2025</td><td class="px-4 py-3"><x-app.status-badge status="Paid" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </x-app.panel>

            <x-app.panel title="Upcoming Events" class="2xl:col-span-3">
                <x-slot:action>
                    <button type="button" class="h-8 rounded-lg border border-zinc-200 px-3 text-xs font-medium text-zinc-600 hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700">View all</button>
                </x-slot:action>
                <div class="divide-y divide-zinc-200 px-4 py-4">
                    <x-app.event-item month="May" day="26" title="Public Holiday" date="Monday, May 26, 2025" time="All Day" />
                    <x-app.event-item month="May" day="31" title="Parent-Teacher Meeting" date="Saturday, May 31, 2025" time="10:00 AM - 1:00 PM" tone="amber" />
                    <x-app.event-item month="Jun" day="02" title="Midterm Exams Begin" date="Monday, June 2, 2025" time="All Day" tone="brand" />
                </div>
            </x-app.panel>
        </div>

        <footer class="py-3 text-center text-sm font-medium text-zinc-800">
            Shaping Futures, Transforming <span class="font-serif text-xl italic text-brand-700">Lives</span>
        </footer>
    </div>
</x-layouts::app>
