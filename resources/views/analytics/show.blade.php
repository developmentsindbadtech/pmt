@extends('layouts.app')

@section('title', $report['title'].' analytics - PMT')

@section('content')
    <div class="mx-auto h-full max-w-5xl overflow-y-auto px-4 pb-10 sm:px-6 lg:px-8">
        <a href="{{ $report['backUrl'] }}" class="text-sm text-slate-500 hover:text-slate-800">&larr; {{ $report['backLabel'] }}</a>
        <div class="mt-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $report['title'] }}</h1>
                    <p class="mt-1 max-w-xl text-sm text-slate-500">{{ $report['fromLabel'] }} – {{ $report['toLabel'] }}. {{ $report['rangeNote'] }}</p>
                </div>
                <form method="GET" class="flex flex-wrap items-end gap-2">
                    <label class="block text-xs font-medium text-slate-500">
                        From
                        <input type="date" name="from" value="{{ $report['from'] }}" class="mt-1 block rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-800" />
                    </label>
                    <label class="block text-xs font-medium text-slate-500">
                        To
                        <input type="date" name="to" value="{{ $report['to'] }}" class="mt-1 block rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-800" />
                    </label>
                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Apply</button>
                </form>
            </div>
        </div>

        <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Open', $report['open'], $report['openHint']],
                ['Closed', $report['closed'], $report['closedHint']],
                ['Overdue', $report['overdue'], 'Open and past the due date'],
                ['Unassigned', $report['unassigned'], 'Open with no owner'],
            ] as [$label, $value, $hint])
                <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold tracking-tight tabular-nums text-slate-900">{{ $value }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        <section class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="text-sm font-semibold tracking-tight text-slate-900">How long finished work took</h2>
            @if($report['sheetNote'])
                <p class="mt-2 text-sm text-slate-600">Sheets keep the current status, not the day a row was marked done, so lead time and cycle time are not shown.</p>
            @else
                <p class="mt-1 text-xs text-slate-500">Calendar days, including weekends. Lead time starts when the item is created. Cycle time starts when it first enters In Progress.</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Lead time</p>
                        @if($report['median'])
                            <p class="mt-1 text-sm text-slate-800">Median <span class="font-semibold tabular-nums">{{ $report['median'] }}</span></p>
                            <p class="text-sm text-slate-800">85% within <span class="font-semibold tabular-nums">{{ $report['leadP85'] }}</span></p>
                            <p class="mt-1 text-xs text-slate-500">{{ $report['medianSample'] }} finished in this range.@if($report['approximate'] > 0) {{ $report['approximate'] }} use the last edit time because the move into the finished column was not recorded.@endif</p>
                        @else
                            <p class="mt-1 text-sm text-slate-500">Appears after an item reaches a finished column in this range.</p>
                        @endif
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Cycle time</p>
                        @if($report['cycleMedian'])
                            <p class="mt-1 text-sm text-slate-800">Median <span class="font-semibold tabular-nums">{{ $report['cycleMedian'] }}</span></p>
                            <p class="text-sm text-slate-800">85% within <span class="font-semibold tabular-nums">{{ $report['cycleP85'] }}</span></p>
                            <p class="mt-1 text-xs text-slate-500">{{ $report['cycleSample'] }} {{ $report['cycleSample'] === 1 ? 'item' : 'items' }} entered In Progress before finishing.@if($report['cycleApproximate'] > 0) {{ $report['cycleApproximate'] }} started before the first recorded move.@endif</p>
                        @else
                            <p class="mt-1 text-sm text-slate-500">Appears after an item enters In Progress and then a finished column in this range.</p>
                        @endif
                    </div>
                </div>
            @endif
            @if($report['archived'] > 0)
                <p class="mt-3 text-sm text-slate-600">{{ $report['archived'] }} archived {{ $report['archived'] === 1 ? 'item is' : 'items are' }} left out of these counts.</p>
            @endif
        </section>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            @foreach ($report['pies'] as $pie)
                <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 class="text-sm font-semibold tracking-tight text-slate-900">{{ $pie['title'] }}</h2>
                    @if($pie['total'] === 0)
                        <p class="mt-6 text-sm text-slate-500">{{ $report['snapshotEmpty'] }}</p>
                    @else
                        <div class="mt-4 flex flex-wrap items-center gap-6">
                            <div class="relative h-36 w-36 shrink-0">
                                <svg viewBox="0 0 32 32" class="h-36 w-36" aria-hidden="true">
                                    @foreach ($pie['slices'] as $slice)
                                        <path d="{{ $slice['d'] }}" fill="{{ $slice['color'] }}"><title>{{ $slice['name'] }}: {{ $slice['count'] }}</title></path>
                                    @endforeach
                                    <circle cx="16" cy="16" r="9" fill="#ffffff"></circle>
                                </svg>
                                <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                                    <span class="text-xl font-semibold tabular-nums tracking-tight text-slate-900">{{ $pie['total'] }}</span>
                                    <span class="text-[10px] uppercase tracking-wide text-slate-400">items</span>
                                </div>
                            </div>
                            <ul class="min-w-0 flex-1 space-y-2">
                                @foreach ($pie['slices'] as $slice)
                                    <li class="flex items-center gap-2 text-sm">
                                        <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $slice['color'] }}"></span>
                                        <span class="min-w-0 flex-1 truncate text-slate-700">{{ $slice['name'] }}</span>
                                        <span class="tabular-nums text-slate-500">{{ $slice['count'] }} · {{ $slice['percent'] }}%</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </section>
            @endforeach
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="text-sm font-semibold tracking-tight text-slate-900">Where work sits</h2>
                <ul class="mt-4 space-y-2.5">
                    @forelse ($report['statuses'] as $status)
                        <li class="flex items-center gap-3">
                            <span class="w-28 shrink-0 truncate text-sm text-slate-700" title="{{ $status['name'] }}">{{ $status['name'] }}</span>
                            <span class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                                <span class="block h-2 rounded-full {{ $status['tone'] }}" style="width: {{ $status['count'] > 0 ? max(8, (int) round($status['count'] / $report['statusMax'] * 100)) : 0 }}%"></span>
                            </span>
                            <span class="w-6 text-right text-sm tabular-nums text-slate-700">{{ $status['count'] }}</span>
                        </li>
                    @empty
                        <li class="text-sm text-slate-500">No columns yet.</li>
                    @endforelse
                </ul>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="text-sm font-semibold tracking-tight text-slate-900">{{ $report['ageTitle'] }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ $report['ageHint'] }}</p>
                <ul class="mt-4 space-y-2.5">
                    @foreach ($report['aging'] as $bucket)
                        <li class="flex items-center gap-3">
                            <span class="w-28 shrink-0 text-sm text-slate-700">{{ $bucket['label'] }}</span>
                            <span class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                                <span class="block h-2 rounded-full bg-slate-500" style="width: {{ $bucket['count'] > 0 ? max(8, (int) round($bucket['count'] / max(1, $report['open']) * 100)) : 0 }}%"></span>
                            </span>
                            <span class="w-6 text-right text-sm tabular-nums text-slate-700">{{ $bucket['count'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>

        <section class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold tracking-tight text-slate-900">{{ $report['chartClosed'] ? 'Opened and closed' : 'Rows opened' }}</h2>
                <p class="text-xs text-slate-500"><span class="inline-block h-2 w-2 rounded-full bg-slate-300"></span> Opened @if($report['chartClosed'])<span class="ml-2 inline-block h-2 w-2 rounded-full bg-emerald-500"></span> Closed @endif</p>
            </div>
            <div class="mt-4 flex items-end gap-2">
                @foreach ($report['weeks'] as $week)
                    <div class="flex min-w-0 flex-1 flex-col items-center gap-1">
                        <div class="flex h-24 w-full items-end justify-center gap-1">
                            <span class="w-2 rounded-t bg-slate-300" style="height: {{ $week['opened'] > 0 ? max(8, (int) round($week['opened'] / $report['weekMax'] * 100)) : 0 }}%" title="{{ $week['opened'] }} opened"></span>
                            @if($report['chartClosed'])
                                <span class="w-2 rounded-t bg-emerald-500" style="height: {{ $week['closed'] > 0 ? max(8, (int) round($week['closed'] / $report['weekMax'] * 100)) : 0 }}%" title="{{ $week['closed'] }} closed"></span>
                            @endif
                        </div>
                        <span class="truncate text-[10px] text-slate-500">{{ $week['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="text-sm font-semibold tracking-tight text-slate-900">Who has open work</h2>
                @if($report['people'] === [])
                    <p class="mt-3 text-sm text-slate-500">No open work.</p>
                @else
                    <table class="mt-3 w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="py-1 font-medium">Person</th>
                                <th class="py-1 text-right font-medium">Open</th>
                                <th class="py-1 text-right font-medium">Overdue</th>
                                <th class="py-1 text-right font-medium">{{ $report['oldestHeading'] }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['people'] as $person)
                                <tr class="border-t border-slate-100">
                                    <td class="py-2 text-slate-800">
                                        @if(! empty($person['url']))
                                            <a href="{{ $person['url'] }}" class="font-medium text-slate-800 hover:text-blue-700" title="Show open work for {{ $person['name'] }}">{{ $person['name'] }}</a>
                                        @else
                                            {{ $person['name'] }}
                                        @endif
                                    </td>
                                    <td class="py-2 text-right tabular-nums text-slate-700">{{ $person['open'] }}</td>
                                    <td class="py-2 text-right tabular-nums {{ $person['overdue'] > 0 ? 'text-rose-700' : 'text-slate-700' }}">{{ $person['overdue'] }}</td>
                                    <td class="py-2 text-right tabular-nums text-slate-700">{{ $person['oldest'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="text-sm font-semibold tracking-tight text-slate-900">Needs attention</h2>
                @if($report['attention'] === [])
                    <p class="mt-3 text-sm text-slate-500">Nothing overdue, stuck, high priority, or sitting in one column for two weeks.</p>
                @else
                    <ul class="mt-3 divide-y divide-slate-100">
                        @foreach ($report['attention'] as $item)
                            <li class="py-2">
                                <a href="{{ $item['url'] }}" class="text-sm font-medium text-slate-900 hover:text-blue-700">{{ $item['title'] }}</a>
                                <p class="text-xs text-slate-500">{{ $item['meta'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
@endsection
