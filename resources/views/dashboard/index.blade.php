@extends('layouts.main')

@php
    // Status colour never carries meaning on its own — each state ships with an
    // icon and a label as well.
    $states = [
        'ok' => ['Everything processed', 'var(--status-good)', 'M4.5 12.75l6 6 9-13.5'],
        'warn' => ['Needs a look', 'var(--status-warning)', 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z'],
        'error' => ['Something is stuck', 'var(--status-critical)', 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z'],
    ];
    [$stateLabel, $stateColor, $stateIcon] = $states[$pipeline['status']];

    $categoryTotal = array_sum($byCategory);
    $categoryMax = $byCategory !== [] ? max($byCategory) : 0.0;
@endphp

@section('content')
    <style>
        /*
         * Chart roles, not raw hex, so a palette change happens in one place.
         * The app is light-mode throughout, so this commits to one surface
         * rather than shipping an unvalidated automatic flip.
         */
        .viz-root {
            --series-1: #2a78d6;
            --track: #e9e8e4;
            --status-good: #0ca30c;
            --status-warning: #fab219;
            --status-critical: #d03b3b;
        }
    </style>

    <div class="viz-root space-y-4">

        {{-- 1 — Pipeline. The bot, the queue and the Wallet push all run with
             nobody watching, so a receipt stuck in `jobs` is otherwise silent. --}}
        <section class="bg-white rounded-lg shadow-md p-4" style="border-left: 4px solid {{ $stateColor }}">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 shrink-0" style="color: {{ $stateColor }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $stateIcon }}"/>
                </svg>
                <h2 class="font-semibold text-gray-900">{{ $stateLabel }}</h2>
            </div>

            <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-4 text-sm">
                <div>
                    <dt class="text-gray-500">Last receipt</dt>
                    @if ($pipeline['lastReceipt'])
                        <dd class="text-gray-900 mt-1">
                            {{ $pipeline['lastReceipt']['shop'] }} · {{ $pipeline['lastReceipt']['date'] }}
                        </dd>
                        <dd class="text-gray-500">
                            {{ $pipeline['lastReceipt']['items'] }} items ·
                            {{ number_format($pipeline['lastReceipt']['total'], 2) }} € ·
                            received {{ $pipeline['lastReceipt']['receivedAt']->diffForHumans() }}
                        </dd>
                    @else
                        <dd class="text-gray-900 mt-1">None yet</dd>
                    @endif
                </div>

                <div>
                    <dt class="text-gray-500">Queue</dt>
                    <dd class="text-gray-900 mt-1">
                        {{ $pipeline['pendingJobs'] }} waiting
                    </dd>
                    @if ($pipeline['queueStalled'])
                        {{-- The jobs themselves look healthy while they sit there.
                             Their age is the only thing that gives away a dead worker. --}}
                        <dd class="mt-0.5 font-medium" style="color: var(--status-critical)">
                            Nothing picked up for {{ $pipeline['oldestJobMinutes'] }} min — is the worker running?
                        </dd>
                    @elseif ($pipeline['pendingJobs'] > 0)
                        <dd class="text-gray-500">Being processed now</dd>
                    @else
                        <dd class="text-gray-500">Empty</dd>
                    @endif
                </div>

                <div>
                    <dt class="text-gray-500">Failed jobs</dt>
                    <dd class="mt-1 {{ $pipeline['failedJobs'] > 0 ? 'font-medium' : 'text-gray-900' }}"
                        @if ($pipeline['failedJobs'] > 0) style="color: var(--status-critical)" @endif>
                        {{ $pipeline['failedJobs'] }}
                    </dd>
                    @if ($pipeline['failedJobs'] > 0)
                        <dd class="text-gray-500"><code>php artisan queue:retry all</code></dd>
                    @endif
                </div>

                <div>
                    <dt class="text-gray-500">Wallet push</dt>
                    @if (! $pipeline['walletEnabled'])
                        <dd class="text-gray-900 mt-1">Off</dd>
                        <dd class="text-gray-500">Receipts are saved, nothing is sent</dd>
                    @elseif ($pipeline['walletPending'] > 0)
                        <dd class="mt-1 font-medium" style="color: var(--status-warning)">
                            {{ $pipeline['walletPending'] }} not pushed
                        </dd>
                    @else
                        <dd class="text-gray-900 mt-1">All pushed</dd>
                    @endif
                </div>
            </dl>
        </section>

        {{-- 2 — Spending. The number the whole thing exists for. --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-lg shadow-md p-4">
                <div class="text-gray-500 text-sm">Spent in {{ $spending['month'] }}</div>
                <div class="text-3xl font-semibold text-gray-900 mt-1 tabular-nums">
                    {{ number_format($spending['total'], 2) }} €
                </div>
                @if ($spending['change'] === null)
                    <div class="text-gray-500 text-sm mt-1">No receipts in {{ $spending['previousMonth'] }} to compare</div>
                @else
                    <div class="text-sm mt-1"
                         style="color: {{ $spending['change'] > 0 ? 'var(--status-critical)' : 'var(--status-good)' }}">
                        {{ $spending['change'] > 0 ? '▲' : '▼' }}
                        {{ number_format(abs($spending['change']), 1) }}% vs {{ $spending['previousMonth'] }}
                        <span class="text-gray-500">({{ number_format($spending['previousTotal'], 2) }} €)</span>
                    </div>
                @endif
            </div>

            <div class="bg-white rounded-lg shadow-md p-4">
                <div class="text-gray-500 text-sm">Receipts</div>
                <div class="text-3xl font-semibold text-gray-900 mt-1 tabular-nums">{{ $spending['receipts'] }}</div>
                <div class="text-gray-500 text-sm mt-1">this month</div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-4">
                <div class="text-gray-500 text-sm">Average receipt</div>
                <div class="text-3xl font-semibold text-gray-900 mt-1 tabular-nums">
                    {{ number_format($spending['averageReceipt'], 2) }} €
                </div>
                <div class="text-gray-500 text-sm mt-1">{{ $spending['items'] }} line items</div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-4">
                <div class="text-gray-500 text-sm">Running out</div>
                <div class="text-3xl font-semibold text-gray-900 mt-1 tabular-nums">{{ count($shoppingList->overdue) }}</div>
                <div class="text-gray-500 text-sm mt-1">
                    overdue · {{ count($shoppingList->dueSoon) }} due soon
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

            {{-- 3 — By category. Ten categories compared by magnitude: bars in one
                 hue, sorted, with every value printed. A doughnut here would ask
                 the reader to compare ten angles. --}}
            <section class="bg-white rounded-lg shadow-md p-4">
                <h2 class="font-semibold text-gray-900">Spending by category</h2>
                <p class="text-gray-500 text-sm">{{ $spending['month'] }}</p>

                @if ($byCategory === [])
                    <p class="text-gray-500 text-sm mt-4">No receipts this month yet.</p>
                @else
                    <div class="mt-4 space-y-2">
                        @foreach ($byCategory as $name => $amount)
                            @php $share = $categoryTotal > 0 ? $amount / $categoryTotal * 100 : 0; @endphp
                            <div class="grid grid-cols-[9rem_1fr_5.5rem] items-center gap-3"
                                 title="{{ $name }}: {{ number_format($amount, 2) }} € — {{ number_format($share, 1) }}% of the month">
                                <div class="text-sm text-gray-700 truncate">{{ $name }}</div>
                                <div class="h-2 rounded-full" style="background: var(--track)">
                                    <div class="h-2 rounded-full"
                                         style="width: {{ $categoryMax > 0 ? max(1.5, round($amount / $categoryMax * 100, 2)) : 0 }}%; background: var(--series-1)"></div>
                                </div>
                                <div class="text-sm text-gray-900 text-right tabular-nums">
                                    {{ number_format($amount, 2) }} €
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            {{-- 4 — Time to buy. Same prediction the bot's /shoplist runs on. --}}
            <section class="bg-white rounded-lg shadow-md p-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="font-semibold text-gray-900">Time to buy</h2>
                    <span class="text-gray-500 text-sm">{{ $shoppingList->candidateCount }} products predictable</span>
                </div>

                @if ($dueProducts === [])
                    <p class="text-gray-500 text-sm mt-4">
                        {{-- An empty list alone cannot tell these two apart. --}}
                        @if ($shoppingList->candidateCount === 0)
                            Nothing is predictable yet — a product needs
                            {{ config('shoplist.min_purchases') }} purchases on different days first.
                        @else
                            Nothing is due today.
                        @endif
                    </p>
                @else
                    <ul class="mt-3 divide-y divide-gray-100">
                        @foreach ($dueProducts as $item)
                            <li class="py-2 flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-sm text-gray-900 truncate">{{ $item->name }}</div>
                                    <div class="text-xs text-gray-500">
                                        every {{ $item->intervalDays }} d · last bought
                                        {{ $item->lastPurchase->format('d.m.Y') }}
                                    </div>
                                </div>
                                @if ($item->isOverdue())
                                    <span class="shrink-0 text-xs font-medium px-2 py-0.5 rounded-full"
                                          style="color: #8f2626; background: #fae3e3">
                                        {{ $item->daysOverdue() }} d overdue
                                    </span>
                                @else
                                    <span class="shrink-0 text-xs text-gray-600 px-2 py-0.5 rounded-full bg-gray-100">
                                        in {{ $item->daysUntilDue() }} d
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($shoppingList->snoozedCount > 0)
                        <p class="text-gray-500 text-xs mt-3">{{ $shoppingList->snoozedCount }} snoozed from the bot</p>
                    @endif
                @endif
            </section>
        </div>

        {{-- Last receipts — the quickest way to see the parser did not invent
             anything: the totals here should match the paper. --}}
        <section class="bg-white rounded-lg shadow-md p-4">
            <h2 class="font-semibold text-gray-900">Latest receipts</h2>

            @if ($receipts === [])
                <p class="text-gray-500 text-sm mt-4">Nothing uploaded yet. Send a receipt to the Telegram bot.</p>
            @else
                <div class="relative overflow-x-auto mt-3">
                    <table class="w-full text-sm text-left text-gray-600">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                            <tr>
                                <th scope="col" class="px-4 py-2">Date</th>
                                <th scope="col" class="px-4 py-2">Shop</th>
                                <th scope="col" class="px-4 py-2 text-right">Items</th>
                                <th scope="col" class="px-4 py-2 text-right">Total</th>
                                <th scope="col" class="px-4 py-2">Received</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($receipts as $receipt)
                                <tr class="bg-white border-b last:border-0">
                                    <td class="px-4 py-2 text-gray-900">{{ $receipt['date'] }}</td>
                                    <td class="px-4 py-2">{{ $receipt['shop'] }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $receipt['items'] }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-gray-900">
                                        {{ number_format($receipt['total'], 2) }} €
                                    </td>
                                    <td class="px-4 py-2" title="{{ $receipt['filename'] }}">
                                        {{ $receipt['receivedAt']->diffForHumans() }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
