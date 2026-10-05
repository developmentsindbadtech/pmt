<?php

namespace App\Services;

use App\Models\Board;
use App\Models\Group;
use App\Models\Item;
use App\Models\ItemActivity;
use App\Models\Sheet;
use App\Models\SheetColumn;
use App\Models\SheetRow;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FlowAnalytics
{
    /**
     * Current flow of one board: open, closed, pace, and who is holding work.
     *
     * @return array<string, mixed>
     */
    public static function forBoard(Board $board, ?Carbon $from = null, ?Carbon $to = null): array
    {
        [$from, $to] = self::bounds($from, $to);
        $groups = $board->groups()->orderBy('position')->get();
        $doneIds = $groups->filter(fn (Group $group) => $group->isDone())->pluck('id')->all();
        $finishedNames = $groups->filter(fn (Group $group) => $group->isDone())
            ->map(fn (Group $group) => mb_strtolower($group->name))
            ->all();
        $groupById = $groups->keyBy('id');

        $items = Item::query()
            ->where('board_id', $board->id)
            ->get(['id', 'number', 'name', 'item_type', 'priority', 'group_id', 'assignee_id', 'due_at', 'created_at', 'updated_at', 'archived_at']);

        $activities = $items->isEmpty()
            ? collect()
            : ItemActivity::query()
                ->where('type', 'status_changed')
                ->whereIn('item_id', $items->pluck('id'))
                ->orderBy('created_at')
                ->get(['item_id', 'old_value', 'new_value', 'created_at'])
                ->groupBy('item_id');

        $active = $items->filter(fn (Item $item) => $item->archived_at === null);
        $inDone = fn (Item $item) => in_array($item->group_id, $doneIds, true);
        $inRange = fn (?Carbon $at) => $at !== null && $at->between($from, $to);
        $columnEntered = function (Item $item) use ($activities, $groupById): Carbon {
            $group = $groupById->get($item->group_id);

            return self::enteredColumnAt($item, $activities->get($item->id, collect()), $group?->name);
        };

        $closes = [];
        $closeApproximate = [];
        foreach ($active->filter($inDone) as $item) {
            [$at, $approx] = self::closedAt($item, $activities->get($item->id, collect()), $finishedNames);
            $closes[$item->id] = $at;
            $closeApproximate[$item->id] = $approx;
        }

        $open = $active->reject($inDone);
        $closed = $active->filter($inDone)->filter(fn (Item $item) => $inRange($closes[$item->id] ?? null));

        $leadDays = [];
        $cycleTimeDays = [];
        $approximate = 0;
        $cycleApproximate = 0;
        foreach ($closed as $item) {
            $at = $closes[$item->id];
            $leadDays[] = self::daysBetween($item->created_at, $at);
            if ($closeApproximate[$item->id] ?? false) {
                $approximate++;
            }
            $started = self::startedAt($item, $activities->get($item->id, collect()));
            if ($started && $started[0]->lte($at)) {
                $cycleTimeDays[] = self::daysBetween($started[0], $at);
                if ($started[1]) {
                    $cycleApproximate++;
                }
            }
        }

        $names = User::query()
            ->whereIn('id', $open->pluck('assignee_id')->filter()->unique()->all() ?: [0])
            ->pluck('name', 'id');

        $statuses = $groups->map(function (Group $group) use ($active) {
            $count = $active->where('group_id', $group->id)->count();

            return [
                'name' => $group->name,
                'count' => $count,
                'tone' => self::tone($group->name, $group->isDone()),
                'color' => self::color($group->name, $group->isDone()),
            ];
        })->values();

        if ($active->whereNull('group_id')->isNotEmpty() && $groups->isNotEmpty()) {
            $statuses->push([
                'name' => 'No status',
                'count' => $active->whereNull('group_id')->count(),
                'tone' => 'bg-slate-300',
                'color' => '#cbd5e1',
            ]);
        }

        $attention = $open
            ->map(function (Item $item) use ($board, $groupById, $names, $columnEntered) {
                $group = $groupById->get($item->group_id);
                $reasons = [];
                if (self::isOverdue($item->due_at)) {
                    $reasons[] = 'Overdue';
                }
                if ($group && self::isConcern($group->name)) {
                    $reasons[] = $group->name;
                }
                if (in_array($item->priority, ['critical', 'high'], true)) {
                    $reasons[] = ucfirst($item->priority).' priority';
                }
                $age = self::daysBetween($columnEntered($item), now());
                if ($age >= 14) {
                    $reasons[] = self::dayLabel($age).' in this column';
                }
                if ($reasons === []) {
                    return null;
                }

                $who = $item->assignee_id ? ($names[$item->assignee_id] ?? 'Someone') : 'Unassigned';
                $due = $item->due_at ? 'Due '.$item->due_at->format('M j') : 'No due date';

                return [
                    'title' => '#'.$item->number.' '.$item->name,
                    'meta' => implode(' · ', $reasons).' · '.$who.' · '.$due,
                    'url' => route('boards.show.item', ['board' => $board->id, 'item' => $item->number]),
                    'rank' => (self::isOverdue($item->due_at) ? 0 : 1).sprintf('%05d', 99999 - min($age, 99999)),
                ];
            })
            ->filter()
            ->sortBy('rank')
            ->take(8)
            ->values()
            ->map(fn (array $row) => ['title' => $row['title'], 'meta' => $row['meta'], 'url' => $row['url']])
            ->all();

        $report = self::report(
            kind: 'board',
            title: $board->name,
            backUrl: route('boards.show', $board),
            backLabel: 'Board',
            open: $open,
            closedCount: $closed->count(),
            cycleDays: $leadDays,
            approximate: $approximate,
            statuses: $statuses->all(),
            weeks: self::weeks($active, $closes, $from, $to),
            people: self::linkPeople(
                self::people($open, $names, fn (Item $item) => $item->assignee_id, $columnEntered, fn (Item $item) => $item->due_at),
                fn (int $id) => route('boards.show', ['board' => $board, 'assignee' => $id === 0 ? 'unassigned' : $id]),
            ),
            attention: $attention,
            archived: $items->filter(fn (Item $item) => $item->archived_at !== null && $inRange($item->created_at))->count(),
            openHint: $open->where('item_type', 'bug')->count().' bugs · '.$open->where('item_type', '!=', 'bug')->count().' tasks',
            chartClosed: true,
            from: $from,
            to: $to,
            pies: [
                self::pie('Status', $statuses->map(fn (array $row) => [
                    'name' => $row['name'],
                    'count' => $row['count'],
                    'color' => $row['color'],
                ])->all()),
                self::pie('Type', [
                    ['name' => 'Tasks', 'count' => $open->where('item_type', '!=', 'bug')->count(), 'color' => '#d97706'],
                    ['name' => 'Bugs', 'count' => $open->where('item_type', 'bug')->count(), 'color' => '#e11d48'],
                ]),
            ],
        );

        return array_merge($report, self::timing($leadDays, $cycleTimeDays, $cycleApproximate), [
            'rangeNote' => 'Open is everything still in progress right now. A ticket in Closed is done. Closed, lead time, and cycle time follow the dates above. The last 30 days is the default, because one week is usually too few finished items for a reliable 85th percentile.',
            'ageTitle' => 'Time in the current column',
            'ageHint' => 'How long each open item has sat in its column. Fourteen days or more is flagged below.',
            'oldestHeading' => 'In column',
            'snapshotEmpty' => 'No work right now.',
            'closedHint' => 'Finished in this range',
            'aging' => self::aging($open, $columnEntered),
        ]);
    }

    /**
     * Current flow of one sheet, from its Status, Owner, and Due columns.
     *
     * @return array<string, mixed>
     */
    public static function forSheet(Sheet $sheet, ?Carbon $from = null, ?Carbon $to = null): array
    {
        [$from, $to] = self::bounds($from, $to);
        $columns = $sheet->columns()->orderBy('position')->get();
        $statusCol = $columns->first(fn (SheetColumn $col) => $col->type === 'status' && mb_strtolower($col->name) === 'status')
            ?? $columns->first(fn (SheetColumn $col) => $col->type === 'status' && mb_strtolower($col->name) !== 'priority');
        $ownerCol = $columns->first(fn (SheetColumn $col) => $col->type === 'person' && mb_strtolower($col->name) === 'owner')
            ?? $columns->first(fn (SheetColumn $col) => $col->type === 'person');
        $dueCol = $columns->first(fn (SheetColumn $col) => $col->type === 'date' && mb_strtolower($col->name) === 'due')
            ?? $columns->first(fn (SheetColumn $col) => $col->type === 'date');
        $priorityCol = $columns->first(fn (SheetColumn $col) => $col->type === 'status' && mb_strtolower($col->name) === 'priority');
        $titleCol = $columns->first(fn (SheetColumn $col) => mb_strtolower($col->name) === 'title')
            ?? $columns->first(fn (SheetColumn $col) => $col->type === 'text');

        $rows = SheetRow::query()
            ->where('sheet_id', $sheet->id)
            ->get(['id', 'values', 'created_at', 'updated_at', 'archived_at']);

        $active = $rows->filter(fn (SheetRow $row) => $row->archived_at === null)->map(function (SheetRow $row) use ($statusCol, $ownerCol, $dueCol, $priorityCol, $titleCol) {
            $values = $row->values ?? [];
            $status = $statusCol ? trim((string) ($values[(string) $statusCol->id] ?? '')) : '';
            $owner = $ownerCol ? (int) ($values[(string) $ownerCol->id] ?? 0) : 0;
            $dueRaw = $dueCol ? ($values[(string) $dueCol->id] ?? null) : null;
            $due = null;
            if (is_string($dueRaw) && $dueRaw !== '') {
                try {
                    $due = Carbon::parse($dueRaw)->startOfDay();
                } catch (\Throwable) {
                    $due = null;
                }
            }
            $title = $titleCol ? trim((string) ($values[(string) $titleCol->id] ?? '')) : '';

            return [
                'id' => $row->id,
                'title' => $title !== '' ? $title : 'Untitled row',
                'status' => $status,
                'done' => $statusCol ? $statusCol->statusIsFinished($status) : false,
                'owner' => $owner > 0 ? $owner : null,
                'due' => $due,
                'priority' => $priorityCol ? trim((string) ($values[(string) $priorityCol->id] ?? '')) : '',
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        });

        $open = $active->filter(fn (array $row) => ! $row['done']);
        $closed = $active->filter(fn (array $row) => $row['done']);

        $closes = [];

        $names = User::query()
            ->whereIn('id', $open->pluck('owner')->filter()->unique()->all() ?: [0])
            ->pluck('name', 'id');

        $counts = [];
        foreach ($statusCol->options ?? [] as $option) {
            $label = trim((string) $option);
            if ($label !== '') {
                $counts[$label] = 0;
            }
        }
        foreach ($active as $row) {
            $label = $row['status'] !== '' ? $row['status'] : 'No status';
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        if (! isset($counts['No status']) && $active->contains(fn (array $row) => $row['status'] === '')) {
            $counts['No status'] = $active->where('status', '')->count();
        }

        $statuses = collect($counts)->map(fn (int $count, string $name) => [
            'name' => $name,
            'count' => $count,
            'tone' => $name === 'No status' ? 'bg-slate-300' : self::tone($name, $statusCol?->statusIsFinished($name) ?? false),
            'color' => $name === 'No status' ? '#cbd5e1' : self::color($name, $statusCol?->statusIsFinished($name) ?? false),
        ])->values()->all();

        $attention = $open
            ->map(function (array $row) use ($names, $sheet) {
                $reasons = [];
                if (self::isOverdue($row['due'])) {
                    $reasons[] = 'Overdue';
                }
                if ($row['status'] !== '' && self::isConcern($row['status'])) {
                    $reasons[] = $row['status'];
                }
                if (in_array(mb_strtolower($row['priority']), ['critical', 'high'], true)) {
                    $reasons[] = $row['priority'].' priority';
                }
                $age = self::daysBetween($row['created_at'], now());
                if ($age >= 14) {
                    $reasons[] = self::dayLabel($age).' open';
                }
                if ($reasons === []) {
                    return null;
                }
                $who = $row['owner'] ? ($names[$row['owner']] ?? 'Someone') : 'Unassigned';
                $due = $row['due'] ? 'Due '.$row['due']->format('M j') : 'No due date';

                return [
                    'title' => $row['title'],
                    'meta' => implode(' · ', $reasons).' · '.$who.' · '.$due,
                    'url' => route('sheets.show', $sheet),
                    'rank' => (self::isOverdue($row['due']) ? 0 : 1).sprintf('%05d', 99999 - min($age, 99999)),
                ];
            })
            ->filter()
            ->sortBy('rank')
            ->take(8)
            ->values()
            ->map(fn (array $row) => ['title' => $row['title'], 'meta' => $row['meta'], 'url' => $row['url']])
            ->all();

        $openedSource = $rows->map(fn (SheetRow $row) => ['created_at' => $row->created_at]);

        $report = self::report(
            kind: 'sheet',
            title: $sheet->name,
            backUrl: route('sheets.show', $sheet),
            backLabel: 'Sheet',
            open: $open,
            closedCount: $closed->count(),
            cycleDays: [],
            approximate: 0,
            statuses: $statuses,
            weeks: self::weeks($openedSource, $closes, $from, $to),
            people: self::linkPeople(
                self::people(
                    $open,
                    $names,
                    fn (array $row) => $row['owner'],
                    fn (array $row) => $row['created_at'],
                    fn (array $row) => $row['due'],
                ),
                fn (int $id) => route('sheets.show', ['sheet' => $sheet, 'owner' => $id === 0 ? 'unassigned' : $id]),
            ),
            attention: $attention,
            archived: $rows->filter(fn (SheetRow $row) => $row->archived_at !== null)->count(),
            openHint: $statusCol ? 'Still in progress' : 'No Status column yet, so every active row is open',
            sheetNote: true,
            chartClosed: false,
            from: $from,
            to: $to,
            pies: [
                self::pie('Status', array_map(fn (array $row) => [
                    'name' => $row['name'],
                    'count' => $row['count'],
                    'color' => $row['color'],
                ], $statuses)),
                self::pie('Owners', self::ownerSlices($open, $names)),
            ],
        );

        return array_merge($report, self::timing([], [], 0), [
            'rangeNote' => 'Open is every row still in progress right now. A row marked Done is done. This sheet does not record when a status changed, so the finished count is the rows marked Done right now, and the dates apply to rows opened. The last 30 days is the default.',
            'ageTitle' => 'Age of open work',
            'ageHint' => 'Sheets do not record when a status changed, so this is days since the row was created.',
            'oldestHeading' => 'Age',
            'snapshotEmpty' => 'No rows right now.',
            'closedHint' => 'Finished right now',
        ]);
    }

    /**
     * @param  Collection<int, mixed>  $open
     * @param  array<int, int>  $cycleDays
     * @param  array<int, array{name: string, count: int, tone: string}>  $statuses
     * @param  array<int, array{label: string, opened: int, closed: int}>  $weeks
     * @param  array<int, array{name: string, open: int, overdue: int, oldest: string}>  $people
     * @param  array<int, array{title: string, meta: string, url: string}>  $attention
     * @return array<string, mixed>
     */
    private static function report(
        string $kind,
        string $title,
        string $backUrl,
        string $backLabel,
        Collection $open,
        int $closedCount,
        array $cycleDays,
        int $approximate,
        array $statuses,
        array $weeks,
        array $people,
        array $attention,
        int $archived,
        string $openHint,
        bool $sheetNote = false,
        bool $chartClosed = true,
        ?Carbon $from = null,
        ?Carbon $to = null,
        array $pies = [],
    ): array {
        $median = self::median($cycleDays);
        $overdue = $open->filter(function ($row) {
            $due = $row instanceof Item ? $row->due_at : ($row['due'] ?? null);

            return self::isOverdue($due);
        })->count();
        $unassigned = $open->filter(function ($row) {
            $owner = $row instanceof Item ? $row->assignee_id : ($row['owner'] ?? null);

            return $owner === null;
        })->count();

        return [
            'kind' => $kind,
            'title' => $title,
            'backUrl' => $backUrl,
            'backLabel' => $backLabel,
            'asOf' => now()->format('M j, Y'),
            'open' => $open->count(),
            'closed' => $closedCount,
            'overdue' => $overdue,
            'unassigned' => $unassigned,
            'openHint' => $openHint,
            'median' => $median === null ? null : self::dayLabel($median),
            'medianSample' => count($cycleDays),
            'approximate' => $approximate,
            'sheetNote' => $sheetNote,
            'chartClosed' => $chartClosed,
            'from' => ($from ?? now())->toDateString(),
            'to' => ($to ?? now())->toDateString(),
            'fromLabel' => ($from ?? now())->format('M j, Y'),
            'toLabel' => ($to ?? now())->format('M j, Y'),
            'pies' => $pies,
            'statuses' => $statuses,
            'statusMax' => max(1, ...array_column($statuses, 'count') ?: [1]),
            'weeks' => $weeks,
            'weekMax' => max(1, ...array_map(fn (array $week) => max($week['opened'], $week['closed']), $weeks) ?: [1]),
            'aging' => self::aging($open),
            'people' => $people,
            'attention' => $attention,
            'archived' => $archived,
        ];
    }

    /**
     * @param  array<int, int>  $leadDays
     * @param  array<int, int>  $cycleDays
     * @return array<string, mixed>
     */
    private static function timing(array $leadDays, array $cycleDays, int $cycleApproximate): array
    {
        $leadMedian = self::median($leadDays);
        $leadP85 = self::percentile($leadDays, 85);
        $cycleMedian = self::median($cycleDays);
        $cycleP85 = self::percentile($cycleDays, 85);

        return [
            'leadP85' => $leadP85 === null ? null : self::dayLabel($leadP85),
            'cycleMedian' => $cycleMedian === null ? null : self::dayLabel($cycleMedian),
            'cycleP85' => $cycleP85 === null ? null : self::dayLabel($cycleP85),
            'cycleSample' => count($cycleDays),
            'cycleApproximate' => $cycleApproximate,
        ];
    }

    /**
     * Latest move into a finished column. Falls back to the last edit when that move was not recorded.
     *
     * @param  Collection<int, ItemActivity>  $activities
     * @param  array<int, string>  $finishedNames
     * @return array{0: Carbon, 1: bool}
     */
    private static function closedAt(Item $item, Collection $activities, array $finishedNames): array
    {
        $at = null;
        foreach ($activities as $activity) {
            $name = mb_strtolower(trim((string) $activity->new_value));
            if (in_array($name, $finishedNames, true)) {
                $at = $activity->created_at;
            }
        }
        if ($at instanceof Carbon) {
            return [$at, false];
        }

        return [$item->updated_at ?? $item->created_at, true];
    }

    /**
     * First move into In Progress. Null when work never entered that column.
     *
     * @param  Collection<int, ItemActivity>  $activities
     * @return array{0: Carbon, 1: bool}|null
     */
    private static function startedAt(Item $item, Collection $activities): ?array
    {
        foreach ($activities as $activity) {
            if (self::isStartedName($activity->new_value)) {
                return [$activity->created_at, false];
            }
        }
        $first = $activities->first();
        if ($first && self::isStartedName($first->old_value)) {
            return [$item->created_at ?? $first->created_at, true];
        }

        return null;
    }

    /**
     * When the item arrived in its current column. Creation time if it was never moved.
     *
     * @param  Collection<int, ItemActivity>  $activities
     */
    private static function enteredColumnAt(Item $item, Collection $activities, ?string $columnName): Carbon
    {
        $at = null;
        $column = mb_strtolower(trim((string) $columnName));
        if ($column !== '') {
            foreach ($activities as $activity) {
                if (mb_strtolower(trim((string) $activity->new_value)) === $column) {
                    $at = $activity->created_at;
                }
            }
        }

        return $at instanceof Carbon ? $at : ($item->created_at ?? now());
    }

    private static function isStartedName(?string $name): bool
    {
        if ($name === null || trim($name) === '') {
            return false;
        }

        return (bool) preg_match('/\b(?:progress|doing|working)\b/u', mb_strtolower(trim($name)));
    }

    /**
     * @param  Collection<int, mixed>  $createdRows
     * @param  array<int, Carbon>  $closes
     * @return array<int, array{label: string, opened: int, closed: int}>
     */
    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function bounds(?Carbon $from, ?Carbon $to): array
    {
        $end = ($to ?? now())->copy()->endOfDay();
        $start = ($from ?? $end->copy()->subDays(29))->copy()->startOfDay();
        if ($start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }
        if ($start->diffInDays($end) > 366) {
            $start = $end->copy()->subDays(366)->startOfDay();
        }

        return [$start, $end];
    }

    /**
     * @param  array<int, array{name: string, count: int, color: string}>  $slices
     * @return array{title: string, total: int, slices: array<int, array{name: string, count: int, color: string, percent: int, d: string}>}
     */
    private static function pie(string $title, array $slices): array
    {
        $slices = array_values(array_filter($slices, fn (array $slice) => ($slice['count'] ?? 0) > 0));
        $total = (int) array_sum(array_column($slices, 'count'));
        $angle = -90.0;
        $drawn = [];
        foreach ($slices as $slice) {
            $sweep = $total > 0 ? ($slice['count'] / $total) * 360 : 0;
            $drawn[] = [
                'name' => $slice['name'],
                'count' => $slice['count'],
                'color' => $slice['color'],
                'percent' => $total > 0 ? (int) round($slice['count'] / $total * 100) : 0,
                'd' => self::wedge($angle, $angle + $sweep),
            ];
            $angle += $sweep;
        }

        return ['title' => $title, 'total' => $total, 'slices' => $drawn];
    }

    private static function wedge(float $start, float $end): string
    {
        $sweep = $end - $start;
        if ($sweep >= 359.99) {
            return 'M 16 0 A 16 16 0 1 1 15.999 0 Z';
        }
        [$x1, $y1] = self::polar($start);
        [$x2, $y2] = self::polar($end);
        $large = $sweep > 180 ? 1 : 0;

        return sprintf('M 16 16 L %.3f %.3f A 16 16 0 %d 1 %.3f %.3f Z', $x1, $y1, $large, $x2, $y2);
    }

    /**
     * @return array{0: float, 1: float}
     */
    private static function polar(float $degrees): array
    {
        $radians = deg2rad($degrees);

        return [16 + cos($radians) * 16, 16 + sin($radians) * 16];
    }

    private static function color(string $name, bool $done): string
    {
        if ($done) {
            return '#10b981';
        }
        if (self::isConcern($name)) {
            return '#f43f5e';
        }
        $label = mb_strtolower($name);
        if (str_contains($label, 'progress') || str_contains($label, 'doing')) {
            return '#0ea5e9';
        }
        if (str_contains($label, 'ready') || str_contains($label, 'review') || str_contains($label, 'qa')) {
            return '#f59e0b';
        }

        return '#94a3b8';
    }

    /**
     * @param  Collection<int, mixed>  $open
     * @param  Collection<int|string, string>  $names
     * @return array<int, array{name: string, count: int, color: string}>
     */
    private static function ownerSlices(Collection $open, Collection $names): array
    {
        $palette = ['#334155', '#0284c7', '#059669', '#d97706', '#e11d48', '#7c3aed', '#0f766e'];
        $index = 0;

        return $open->groupBy(fn (array $row) => $row['owner'] ?: 0)
            ->map(function (Collection $rows, int|string $id) use ($names, $palette, &$index) {
                $color = ((int) $id) === 0 ? '#94a3b8' : $palette[$index++ % count($palette)];

                return [
                    'name' => ((int) $id) === 0 ? 'Unassigned' : ($names[(int) $id] ?? 'Someone'),
                    'count' => $rows->count(),
                    'color' => $color,
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $createdRows
     * @param  array<int, Carbon>  $closes
     * @return array<int, array{label: string, opened: int, closed: int}>
     */
    private static function weeks(Collection $createdRows, array $closes, ?Carbon $rangeFrom = null, ?Carbon $rangeTo = null): array
    {
        $start = ($rangeFrom ?? now()->copy()->subWeeks(8))->copy()->startOfDay();
        $end = ($rangeTo ?? now())->copy()->endOfDay();
        $span = (int) max(1, floor(($end->getTimestamp() - $start->getTimestamp()) / 86400) + 1);

        if ($span <= 16) {
            $buckets = [];
            for ($i = 0; $i < $span; $i++) {
                $from = $start->copy()->addDays($i)->startOfDay();
                $buckets[] = self::bucket($createdRows, $closes, $from, $from->copy()->endOfDay(), $from->format('M j'));
            }

            return $buckets;
        }

        if ($span <= 120) {
            $buckets = [];
            $cursor = $start->copy()->startOfWeek(Carbon::MONDAY);
            while ($cursor->lte($end) && count($buckets) < 18) {
                $from = $cursor->copy()->max($start);
                $to = $cursor->copy()->endOfWeek(Carbon::SUNDAY)->min($end);
                $buckets[] = self::bucket($createdRows, $closes, $from, $to, $cursor->format('M j'));
                $cursor->addWeek();
            }

            return $buckets;
        }

        $buckets = [];
        $cursor = $start->copy()->startOfMonth();
        while ($cursor->lte($end) && count($buckets) < 13) {
            $from = $cursor->copy()->max($start);
            $to = $cursor->copy()->endOfMonth()->min($end);
            $buckets[] = self::bucket($createdRows, $closes, $from, $to, $cursor->format('M Y'));
            $cursor->addMonth();
        }

        return $buckets;
    }

    /**
     * @param  Collection<int, mixed>  $createdRows
     * @param  array<int, Carbon>  $closes
     * @return array{label: string, opened: int, closed: int}
     */
    private static function bucket(Collection $createdRows, array $closes, Carbon $from, Carbon $to, string $label): array
    {
        return [
            'label' => $label,
            'opened' => $createdRows->filter(function ($row) use ($from, $to) {
                $created = $row instanceof Item ? $row->created_at : ($row['created_at'] ?? null);

                return $created && $created->between($from, $to);
            })->count(),
            'closed' => collect($closes)->filter(fn (Carbon $at) => $at->between($from, $to))->count(),
        ];
    }

    /**
     * @param  Collection<int, mixed>  $open
     * @return array<int, array{label: string, count: int}>
     */
    private static function aging(Collection $open, ?callable $since = null): array
    {
        $buckets = [
            '0–3 days' => 0,
            '4–7 days' => 0,
            '8–14 days' => 0,
            '15–30 days' => 0,
            'Over 30 days' => 0,
        ];
        foreach ($open as $row) {
            $from = $since
                ? $since($row)
                : ($row instanceof Item ? $row->created_at : $row['created_at']);
            $days = self::daysBetween($from instanceof Carbon ? $from : null, now());
            if ($days <= 3) {
                $buckets['0–3 days']++;
            } elseif ($days <= 7) {
                $buckets['4–7 days']++;
            } elseif ($days <= 14) {
                $buckets['8–14 days']++;
            } elseif ($days <= 30) {
                $buckets['15–30 days']++;
            } else {
                $buckets['Over 30 days']++;
            }
        }

        return collect($buckets)->map(fn (int $count, string $label) => ['label' => $label, 'count' => $count])->values()->all();
    }

    /**
     * @param  Collection<int, mixed>  $open
     * @param  Collection<int|string, string>  $names
     * @return array<int, array{id: int, name: string, open: int, overdue: int, oldest: string}>
     */
    private static function people(Collection $open, Collection $names, callable $ownerId, callable $createdAt, callable $dueAt): array
    {
        $grouped = $open->groupBy(fn ($row) => $ownerId($row) ?: 0);

        return $grouped->map(function (Collection $rows, int|string $id) use ($names, $createdAt, $dueAt) {
            $oldest = $rows->max(fn ($row) => self::daysBetween($createdAt($row), now()));

            return [
                'id' => (int) $id,
                'name' => ((int) $id) === 0 ? 'Unassigned' : ($names[(int) $id] ?? 'Someone'),
                'open' => $rows->count(),
                'overdue' => $rows->filter(fn ($row) => self::isOverdue($dueAt($row)))->count(),
                'oldest' => self::dayLabel((int) $oldest),
                'sort' => ((int) $id) === 0 ? 0 : 1,
            ];
        })->sortBy([
            ['sort', 'asc'],
            ['open', 'desc'],
        ])->map(fn (array $row) => [
            'id' => $row['id'],
            'name' => $row['name'],
            'open' => $row['open'],
            'overdue' => $row['overdue'],
            'oldest' => $row['oldest'],
        ])->values()->all();
    }

    /**
     * @param  array<int, array{id: int, name: string, open: int, overdue: int, oldest: string}>  $people
     * @param  callable(int): string  $urlFor
     * @return array<int, array{id: int, name: string, open: int, overdue: int, oldest: string, url: string}>
     */
    private static function linkPeople(array $people, callable $urlFor): array
    {
        return array_map(function (array $person) use ($urlFor) {
            $person['url'] = $urlFor((int) $person['id']);

            return $person;
        }, $people);
    }

    private static function isOverdue(mixed $due): bool
    {
        if (! $due instanceof Carbon) {
            return false;
        }

        return $due->copy()->startOfDay()->lt(now()->startOfDay());
    }

    private static function isConcern(string $name): bool
    {
        $label = mb_strtolower($name);

        return str_contains($label, 'stuck')
            || str_contains($label, 'block')
            || str_contains($label, 'reject');
    }

    private static function tone(string $name, bool $done): string
    {
        if ($done) {
            return 'bg-emerald-500';
        }
        if (self::isConcern($name)) {
            return 'bg-rose-500';
        }
        $label = mb_strtolower($name);
        if (str_contains($label, 'progress') || str_contains($label, 'doing')) {
            return 'bg-sky-500';
        }
        if (str_contains($label, 'ready') || str_contains($label, 'review') || str_contains($label, 'qa')) {
            return 'bg-amber-400';
        }

        return 'bg-slate-400';
    }

    private static function daysBetween(?Carbon $from, ?Carbon $to): int
    {
        if (! $from || ! $to) {
            return 0;
        }

        return (int) max(0, floor(($to->getTimestamp() - $from->getTimestamp()) / 86400));
    }

    /**
     * @param  array<int, int>  $days
     */
    private static function median(array $days): ?int
    {
        if ($days === []) {
            return null;
        }
        sort($days);
        $count = count($days);
        $mid = intdiv($count, 2);
        if ($count % 2 === 1) {
            return $days[$mid];
        }

        return (int) round(($days[$mid - 1] + $days[$mid]) / 2);
    }

    /**
     * Nearest-rank percentile. The 85th is the service level most flow reports publish next to the median.
     *
     * @param  array<int, int>  $days
     */
    private static function percentile(array $days, int $percent): ?int
    {
        if ($days === []) {
            return null;
        }
        sort($days);
        $index = (int) ceil(count($days) * $percent / 100) - 1;

        return $days[max(0, min($index, count($days) - 1))];
    }

    private static function dayLabel(int $days): string
    {
        if ($days < 1) {
            return 'Same day';
        }
        if ($days === 1) {
            return '1 day';
        }

        return $days.' days';
    }
}
