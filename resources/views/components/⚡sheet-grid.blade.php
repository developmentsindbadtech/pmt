<?php

use App\Models\Sheet;
use App\Models\SheetColumn;
use App\Models\SheetRow;
use App\Models\SheetRowComment;
use App\Models\User;
use Livewire\Component;

new class extends Component
{
    public int $sheetId;

    public string $newColName = '';

    public string $newColType = 'text';

    public string $newColOptions = '';

    public bool $showAddColumn = false;

    public ?int $sortColId = null;

    public string $sortDir = 'asc';

    /** Quick filter: open (default) | done | mine | all | archived */
    public string $filter = 'open';

    /** Row detail sidebar (description + comments). */
    public ?int $selectedRowId = null;

    public string $commentBody = '';

    public string $descriptionDraft = '';

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['open', 'done', 'mine', 'all', 'archived'], true) ? $filter : 'open';
    }

    public function archiveRow($rowId): void
    {
        $row = SheetRow::where('sheet_id', $this->sheetId)->whereKey($rowId)->first();
        if (! $row) {
            return;
        }
        $row->archive();
        if ($this->selectedRowId === (int) $rowId) {
            $this->closeRow();
        }
    }

    public function unarchiveRow($rowId): void
    {
        $row = SheetRow::where('sheet_id', $this->sheetId)->whereKey($rowId)->first();
        if (! $row) {
            return;
        }
        $row->unarchive();
    }

    public function openRow($rowId): void
    {
        $rowId = (int) $rowId;
        if ($this->selectedRowId === $rowId) {
            return;
        }

        $row = SheetRow::where('sheet_id', $this->sheetId)->whereKey($rowId)->first();
        if (! $row) {
            return;
        }

        // Persist the open item's description before switching.
        if ($this->selectedRowId) {
            $this->persistDescription();
        }

        $this->selectedRowId = $rowId;
        $this->descriptionDraft = (string) ($row->description ?? '');
        $this->commentBody = '';
        $this->resetValidation();
    }

    public function closeRow(): void
    {
        if ($this->selectedRowId) {
            $this->persistDescription();
        }
        $this->selectedRowId = null;
        $this->commentBody = '';
        $this->descriptionDraft = '';
        $this->resetValidation();
    }

    public function persistDescription(): void
    {
        if (! $this->selectedRowId) {
            return;
        }

        $row = SheetRow::where('sheet_id', $this->sheetId)->whereKey($this->selectedRowId)->first();
        if (! $row) {
            return;
        }

        $description = trim($this->descriptionDraft);
        $row->description = $description === '' ? null : mb_substr($description, 0, 10000);
        $row->save();
    }

    public function updateDescription($rowId, $description): void
    {
        $row = SheetRow::where('sheet_id', $this->sheetId)->whereKey($rowId)->first();
        if (! $row) {
            return;
        }
        $row->description = $description === null || trim((string) $description) === ''
            ? null
            : mb_substr(trim((string) $description), 0, 10000);
        $row->save();

        if ($this->selectedRowId === (int) $rowId) {
            $this->descriptionDraft = (string) ($row->description ?? '');
        }
    }

    public function addComment(): void
    {
        if (! $this->selectedRowId) {
            return;
        }

        $data = $this->validate([
            'commentBody' => 'required|string|max:5000',
        ]);

        $row = SheetRow::where('sheet_id', $this->sheetId)->whereKey($this->selectedRowId)->first();
        if (! $row) {
            return;
        }

        $body = trim($data['commentBody']);

        SheetRowComment::create([
            'sheet_row_id' => $row->id,
            'user_id' => auth()->id(),
            'body' => $body,
        ]);

        $this->notifySheetMentions($row, $body);
        $this->commentBody = '';
    }

    private function notifySheetMentions(SheetRow $row, string $body): void
    {
        if (! config('services.microsoft.client_id') || ! config('services.microsoft.client_secret')) {
            return;
        }

        $sheet = Sheet::find($this->sheetId);
        $mentionedBy = auth()->user();
        if (! $sheet || ! $mentionedBy) {
            return;
        }

        $mentionService = app(\App\Services\MentionService::class);
        $mentionedUserIds = $mentionService->extractMentionsForSheet($body, $sheet);
        $mentionedUserIds = array_filter($mentionedUserIds, fn ($id) => (int) $id !== (int) $mentionedBy->id);

        if (empty($mentionedUserIds)) {
            return;
        }

        $titleCol = SheetColumn::where('sheet_id', $this->sheetId)
            ->where(function ($q) {
                $q->whereRaw('LOWER(name) = ?', ['title'])->orWhere('type', 'text');
            })
            ->orderByRaw("CASE WHEN LOWER(name) = 'title' THEN 0 ELSE 1 END")
            ->orderBy('position')
            ->first();

        $itemTitle = $titleCol
            ? (string) data_get($row->values, (string) $titleCol->id, 'Untitled item')
            : 'Untitled item';
        if ($itemTitle === '') {
            $itemTitle = 'Untitled item';
        }

        $preview = mb_substr(strip_tags($body), 0, 200);
        if (mb_strlen($body) > 200) {
            $preview .= '...';
        }

        $sheetUrl = route('sheets.show', $sheet);
        $html = view('emails.sheet-mention', [
            'mentionedBy' => $mentionedBy,
            'sheet' => $sheet,
            'itemTitle' => $itemTitle,
            'preview' => $preview,
            'sheetUrl' => $sheetUrl,
        ])->render();

        $mail = app(\App\Services\MicrosoftGraphMailService::class);
        foreach (User::whereIn('id', $mentionedUserIds)->get() as $user) {
            if (! $user->email) {
                continue;
            }
            try {
                $mail->sendEmail(
                    $user->email,
                    'You were mentioned on the sheet "'.$sheet->name.'"',
                    $html
                );
            } catch (\Throwable $e) {
                \Log::error('Failed to send sheet mention notification: '.$e->getMessage());
            }
        }
    }

    public function deleteComment($commentId): void
    {
        $comment = SheetRowComment::query()
            ->whereKey($commentId)
            ->whereHas('row', fn ($q) => $q->where('sheet_id', $this->sheetId))
            ->first();

        if (! $comment) {
            return;
        }

        $user = auth()->user();
        if (! $user) {
            return;
        }

        // Author or admin can delete.
        if ($comment->user_id !== $user->id && ! $user->is_admin) {
            return;
        }

        $comment->delete();
    }

    public function sortBy($columnId): void
    {
        $columnId = (int) $columnId;
        if ($this->sortColId === $columnId) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColId = $columnId;
            $this->sortDir = 'asc';
        }
    }

    public function clearSort(): void
    {
        $this->sortColId = null;
        $this->sortDir = 'asc';
    }

    /**
     * Add Owner / Due / Priority columns if this sheet is missing the PM starter set.
     * Also ensures the Status column includes the standard Stuck label.
     * Safe for existing sheets created before the richer defaults.
     */
    public function ensurePmColumns(): void
    {
        $existing = SheetColumn::where('sheet_id', $this->sheetId)->pluck('name')->map(fn ($n) => mb_strtolower($n));
        $position = (int) SheetColumn::where('sheet_id', $this->sheetId)->max('position');

        $defaults = [
            ['name' => 'Owner', 'type' => 'person', 'options' => null],
            ['name' => 'Due', 'type' => 'date', 'options' => null],
            ['name' => 'Priority', 'type' => 'status', 'options' => ['Critical', 'High', 'Medium', 'Low']],
        ];

        foreach ($defaults as $col) {
            if ($existing->contains(mb_strtolower($col['name']))) {
                continue;
            }
            $position++;
            SheetColumn::create([
                'sheet_id' => $this->sheetId,
                'name' => $col['name'],
                'type' => $col['type'],
                'options' => $col['options'],
                'position' => $position,
            ]);
        }

        $this->ensureStandardStatusOptions();
    }

    /**
     * Minimal industry status set: To Do → In Progress → Stuck → Done.
     */
    public function ensureStandardStatusOptions(): void
    {
        $standard = ['To Do', 'In Progress', 'Stuck', 'Done'];

        $statusCol = SheetColumn::query()
            ->where('sheet_id', $this->sheetId)
            ->where('type', 'status')
            ->whereRaw('LOWER(name) = ?', ['status'])
            ->first();

        if (! $statusCol) {
            return;
        }

        $current = collect($statusCol->options ?? [])->map(fn ($o) => trim((string) $o))->filter()->values();
        $merged = $current->all();
        foreach ($standard as $label) {
            $exists = $current->contains(fn ($o) => mb_strtolower($o) === mb_strtolower($label));
            if (! $exists) {
                $merged[] = $label;
            }
        }

        // Keep a clean order for the standard labels; append any custom extras after.
        $ordered = [];
        foreach ($standard as $label) {
            foreach ($merged as $m) {
                if (mb_strtolower($m) === mb_strtolower($label)) {
                    $ordered[] = $label;
                    break;
                }
            }
        }
        foreach ($merged as $m) {
            $isStandard = collect($standard)->contains(fn ($s) => mb_strtolower($s) === mb_strtolower($m));
            if (! $isStandard) {
                $ordered[] = $m;
            }
        }

        $statusCol->options = $ordered;
        $statusCol->save();
    }

    public function renameSheet($name): void
    {
        $user = auth()->user();
        $sheet = Sheet::whereKey($this->sheetId)->first();
        if (! $sheet || ! $user) {
            return;
        }
        // Owner or admin only — matches board rename discipline.
        if (! $user->is_admin && (int) $sheet->created_by !== (int) $user->id) {
            return;
        }
        $name = trim((string) $name);
        if ($name === '') {
            return;
        }
        $sheet->update(['name' => mb_substr($name, 0, 255)]);
    }

    public function mount(int $sheetId): void
    {
        $this->sheetId = $sheetId;

        $user = auth()->user();
        if ($user && ! $user->is_admin) {
            $hasAccess = Sheet::query()
                ->whereKey($sheetId)
                ->visibleTo($user)
                ->exists();
            abort_unless($hasAccess, 403);
        }

        // Keep Status labels complete on older sheets (adds Stuck if missing).
        $this->ensureStandardStatusOptions();
    }

    public function getSheetProperty(): ?Sheet
    {
        return Sheet::find($this->sheetId);
    }

    public function getColumnsProperty()
    {
        return SheetColumn::where('sheet_id', $this->sheetId)->orderBy('position')->get();
    }

    public function getRowsProperty()
    {
        $query = SheetRow::where('sheet_id', $this->sheetId)
            ->withCount('comments')
            ->orderBy('position')
            ->orderBy('id');

        if ($this->filter === 'archived') {
            $query->archived();
        } else {
            $query->active();
        }

        return $query->get();
    }

    public function getSelectedRowProperty(): ?SheetRow
    {
        if (! $this->selectedRowId) {
            return null;
        }

        return SheetRow::with(['comments.user'])
            ->where('sheet_id', $this->sheetId)
            ->whereKey($this->selectedRowId)
            ->first();
    }

    public function getUsersProperty()
    {
        // Person columns offer users assigned to this sheet (managed in User
        // Management). Admins always have access, so they are always included.
        return User::query()
            ->where(function ($q) {
                $q->where('is_admin', true)
                    ->orWhereHas('sheets', fn ($sub) => $sub->whereKey($this->sheetId));
            })
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function addColumn(): void
    {
        $data = $this->validate([
            'newColName' => 'required|string|max:100',
            'newColType' => 'required|in:'.implode(',', array_keys(Sheet::columnTypes())),
            'newColOptions' => 'nullable|string|max:500',
        ]);

        $options = null;
        if ($data['newColType'] === 'status') {
            $options = collect(explode(',', $data['newColOptions']))
                ->map(fn ($s) => trim($s))
                ->filter()
                ->values()
                ->all();
            if (empty($options)) {
                $options = ['To Do', 'In Progress', 'Stuck', 'Done'];
            }
        }

        $position = (int) SheetColumn::where('sheet_id', $this->sheetId)->max('position') + 1;

        SheetColumn::create([
            'sheet_id' => $this->sheetId,
            'name' => $data['newColName'],
            'type' => $data['newColType'],
            'options' => $options,
            'position' => $position,
        ]);

        $this->reset('newColName', 'newColType', 'newColOptions', 'showAddColumn');
    }

    public function renameColumn($columnId, $name): void
    {
        $name = trim((string) $name);
        if ($name === '') {
            return;
        }
        SheetColumn::where('sheet_id', $this->sheetId)->whereKey($columnId)->update(['name' => mb_substr($name, 0, 100)]);
    }

    public function deleteColumn($columnId): void
    {
        SheetColumn::where('sheet_id', $this->sheetId)->whereKey($columnId)->delete();
    }

    public function addRow(): void
    {
        $position = (int) SheetRow::where('sheet_id', $this->sheetId)->whereNull('parent_id')->max('position') + 1;
        SheetRow::create([
            'sheet_id' => $this->sheetId,
            'parent_id' => null,
            'position' => $position,
            'values' => [],
        ]);
    }

    public function addSubRow($parentId): void
    {
        // Only allow subtasks under an existing top-level row in this sheet (one level deep).
        $parent = SheetRow::where('sheet_id', $this->sheetId)
            ->whereNull('parent_id')
            ->whereKey($parentId)
            ->first();
        if (! $parent) {
            return;
        }

        $position = (int) SheetRow::where('sheet_id', $this->sheetId)->where('parent_id', $parent->id)->max('position') + 1;
        SheetRow::create([
            'sheet_id' => $this->sheetId,
            'parent_id' => $parent->id,
            'position' => $position,
            'values' => [],
        ]);
    }

    public function deleteRow($rowId): void
    {
        SheetRow::where('sheet_id', $this->sheetId)->whereKey($rowId)->delete();
        if ($this->selectedRowId === (int) $rowId) {
            $this->closeRow();
        }
    }

    public function updateCell($rowId, $columnId, $value): void
    {
        $row = SheetRow::where('sheet_id', $this->sheetId)->whereKey($rowId)->first();
        if (! $row) {
            return;
        }
        $col = SheetColumn::where('sheet_id', $this->sheetId)->whereKey($columnId)->first();
        if (! $col) {
            return;
        }

        $values = $row->values ?? [];
        $key = (string) $columnId;
        $oldValue = $values[$key] ?? null;

        if ($col->type === 'checkbox') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            $values[$key] = $value;
        } elseif ($value === '' || $value === null) {
            unset($values[$key]);
        } else {
            $values[$key] = is_string($value) ? mb_substr($value, 0, 2000) : $value;
        }

        $row->values = $values;
        $row->save();

        // Notify the newly assigned person when a "person" cell changes to a
        // different, non-empty user (mirrors board assignee notifications).
        if ($col->type === 'person') {
            $newValue = $values[$key] ?? null;
            if ($newValue && (string) $newValue !== (string) $oldValue) {
                $this->notifySheetAssignment((int) $newValue, $col->name);
            }
        }
    }

    private function notifySheetAssignment(int $assigneeId, string $columnName): void
    {
        if (! config('services.microsoft.client_id') || ! config('services.microsoft.client_secret')) {
            return;
        }

        $assignedBy = auth()->user();
        if ($assignedBy && $assignedBy->id === $assigneeId) {
            return; // don't notify yourself
        }

        $assignee = User::find($assigneeId);
        if (! $assignee || ! $assignee->email) {
            return;
        }

        $sheet = Sheet::find($this->sheetId);
        if (! $sheet) {
            return;
        }

        try {
            $html = view('emails.sheet-assignment', [
                'assignedBy' => $assignedBy,
                'assignee' => $assignee,
                'sheet' => $sheet,
                'columnName' => $columnName,
                'sheetUrl' => route('sheets.show', $sheet),
            ])->render();

            app(\App\Services\MicrosoftGraphMailService::class)->sendEmail(
                $assignee->email,
                'You were assigned on the sheet "'.$sheet->name.'"',
                $html
            );
        } catch (\Throwable $e) {
            \Log::error('Failed to send sheet assignment notification: '.$e->getMessage());
        }
    }
};
?>

@php
    $sheet = $this->sheet;
    $columns = $this->columns;
    $rows = $this->rows;
    $users = $this->users;
    $userById = $users->keyBy('id');
    $selectedRow = $this->selectedRow;
    $titleCol = $columns->first(fn ($c) => mb_strtolower($c->name) === 'title')
        ?? $columns->firstWhere('type', 'text');
    $titleColId = $titleCol?->id;

    $isDoneLabel = function (?string $label): bool {
        if ($label === null || $label === '') {
            return false;
        }
        $l = mb_strtolower($label);

        return str_contains($l, 'done')
            || str_contains($l, 'complete')
            || str_contains($l, 'closed');
    };

    // Industry-standard soft chips via CSS classes in app.css (reliable with Vite).
    $statusClass = function (?string $label): string {
        if ($label === null || $label === '') {
            return 'sheet-chip sheet-chip-empty';
        }
        $l = mb_strtolower(trim($label));
        if (str_contains($l, 'done') || str_contains($l, 'complete') || str_contains($l, 'closed')) {
            return 'sheet-chip sheet-chip-status-done';
        }
        if (str_contains($l, 'stuck') || str_contains($l, 'block')) {
            return 'sheet-chip sheet-chip-status-stuck';
        }
        if (str_contains($l, 'progress') || str_contains($l, 'working') || str_contains($l, 'doing')) {
            return 'sheet-chip sheet-chip-status-progress';
        }
        if (str_contains($l, 'to do') || $l === 'todo' || $l === 'new' || str_contains($l, 'backlog') || str_contains($l, 'open')) {
            return 'sheet-chip sheet-chip-status-todo';
        }

        return 'sheet-chip sheet-chip-status-default';
    };

    $priorityClass = function (?string $label): string {
        if ($label === null || $label === '') {
            return 'sheet-chip sheet-chip-empty';
        }
        $l = mb_strtolower(trim($label));
        if (str_contains($l, 'critical') || str_contains($l, 'urgent') || str_contains($l, 'highest')) {
            return 'sheet-chip sheet-chip-priority-critical';
        }
        // Match exact "high" first so "highest" already handled above.
        if ($l === 'high' || preg_match('/\bhigh\b/', $l)) {
            return 'sheet-chip sheet-chip-priority-high';
        }
        if ($l === 'medium' || str_contains($l, 'medium') || str_contains($l, 'normal')) {
            return 'sheet-chip sheet-chip-priority-medium';
        }
        if ($l === 'low' || preg_match('/\blow\b/', $l) || str_contains($l, 'lowest')) {
            return 'sheet-chip sheet-chip-priority-low';
        }

        return 'sheet-chip sheet-chip-priority-default';
    };

    $chipClassForColumn = function ($col, ?string $label) use ($statusClass, $priorityClass): string {
        $name = mb_strtolower((string) ($col->name ?? ''));
        if (str_contains($name, 'priority')) {
            return $priorityClass($label);
        }

        return $statusClass($label);
    };

    $primaryStatusCol = $columns->first(fn ($c) => $c->type === 'status' && mb_strtolower($c->name) === 'status')
        ?? $columns->first(fn ($c) => $c->type === 'status' && ! str_contains(mb_strtolower($c->name), 'priority'));
    $personCol = $columns->first(fn ($c) => $c->type === 'person' && mb_strtolower($c->name) === 'owner')
        ?? $columns->firstWhere('type', 'person');
    $hasPmDefaults = $columns->contains(fn ($c) => mb_strtolower($c->name) === 'owner')
        && $columns->contains(fn ($c) => mb_strtolower($c->name) === 'due')
        && $columns->contains(fn ($c) => mb_strtolower($c->name) === 'priority');

    $sortCol = $sortColId ? $columns->firstWhere('id', $sortColId) : null;
    $sortRows = function ($collection) use ($sortCol, $sortColId, $sortDir) {
        if (! $sortCol) {
            return $collection->sortBy('position')->values();
        }
        $keyFn = function ($r) use ($sortCol, $sortColId) {
            $v = data_get($r->values, (string) $sortColId);
            if ($sortCol->type === 'number') {
                return ($v === null || $v === '') ? INF : (float) $v;
            }
            if ($sortCol->type === 'checkbox') {
                return filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }

            return $v === null ? '' : mb_strtolower((string) $v);
        };
        $sorted = $sortDir === 'desc' ? $collection->sortByDesc($keyFn) : $collection->sortBy($keyFn);

        return $sorted->values();
    };

    $byParent = $rows->groupBy(fn ($r) => (int) ($r->parent_id ?? 0));
    $orderedRows = [];
    $n = 0;
    $authId = (string) (auth()->id() ?? '');

    foreach ($sortRows($byParent->get(0, collect())) as $top) {
        $statusVal = $primaryStatusCol ? (string) data_get($top->values, (string) $primaryStatusCol->id, '') : '';
        $ownerVal = $personCol ? (string) data_get($top->values, (string) $personCol->id, '') : '';
        $done = $isDoneLabel($statusVal !== '' ? $statusVal : null);

        if ($filter === 'open' && $done) {
            continue;
        }
        if ($filter === 'done' && ! $done) {
            continue;
        }
        if ($filter === 'mine' && ($ownerVal === '' || $ownerVal !== $authId)) {
            continue;
        }

        $n++;
        $orderedRows[] = ['row' => $top, 'depth' => 0, 'label' => (string) $n];
        $k = 0;
        foreach ($sortRows($byParent->get($top->id, collect())) as $child) {
            $k++;
            $orderedRows[] = ['row' => $child, 'depth' => 1, 'label' => $n.'.'.$k];
        }
    }

    // Status progress from top-level rows (pre-filter), for the primary status column.
    $statusSummary = [];
    $statusTotal = 0;
    $statusDone = 0;
    if ($primaryStatusCol) {
        foreach ($byParent->get(0, collect()) as $top) {
            $statusTotal++;
            $v = (string) data_get($top->values, (string) $primaryStatusCol->id, '');
            $key = $v !== '' ? $v : 'Blank';
            $statusSummary[$key] = ($statusSummary[$key] ?? 0) + 1;
            if ($isDoneLabel($v !== '' ? $v : null)) {
                $statusDone++;
            }
        }
    }
    $statusPct = $statusTotal > 0 ? (int) round(($statusDone / $statusTotal) * 100) : 0;

    $initials = function (string $name): string {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $a = strtoupper(substr($parts[0] ?? '?', 0, 1));
        $b = strtoupper(substr($parts[count($parts) > 1 ? count($parts) - 1 : 0] ?? '', 0, 1));

        return $a.$b;
    };
@endphp

<div>
    @if($sheet)
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition-[margin] duration-200 {{ $selectedRow ? 'lg:mr-[28rem]' : '' }}">
        @php
            $canManageSheet = auth()->user()?->is_admin || (int) $sheet->created_by === (int) auth()->id();
        @endphp
        {{-- Title + progress --}}
        <div class="border-b border-gray-100 px-4 py-3 sm:px-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div x-data="{ editing: false }" class="min-w-0 flex-1">
                    @if($canManageSheet)
                        <h2
                            x-show="!editing"
                            @click="editing = true; $nextTick(() => { $refs.sheetTitle.focus(); $refs.sheetTitle.select(); })"
                            class="-mx-1 inline-block max-w-full cursor-text truncate rounded px-1 text-xl font-semibold tracking-tight text-gray-900 hover:bg-gray-50"
                            title="Click to rename"
                        >{{ $sheet->name }}</h2>
                        <input
                            x-show="editing" x-cloak x-ref="sheetTitle" type="text" value="{{ $sheet->name }}"
                            @keydown.enter.prevent="$refs.sheetTitle.blur()"
                            @keydown.escape="editing = false"
                            @blur="editing = false; $wire.renameSheet($refs.sheetTitle.value)"
                            class="-mx-1 w-full max-w-md rounded border border-gray-300 px-1 text-xl font-semibold text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                        />
                    @else
                        <h2 class="truncate text-xl font-semibold tracking-tight text-gray-900">{{ $sheet->name }}</h2>
                    @endif
                    @if($primaryStatusCol && $statusTotal > 0)
                        <div class="mt-2 flex max-w-md items-center gap-2">
                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-100">
                                <div class="h-full rounded-full bg-emerald-400/80 transition-all" style="width: {{ $statusPct }}%"></div>
                            </div>
                            <span class="shrink-0 text-xs tabular-nums text-gray-500">{{ $statusPct }}% done · {{ $statusDone }}/{{ $statusTotal }}</span>
                        </div>
                    @endif
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    @if($canManageSheet && auth()->user()?->is_admin)
                        <a
                            href="{{ route('user-management.index', ['sheet' => $sheet->id]) }}#sheets"
                            class="rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50"
                        >Manage access</a>
                    @endif
                    @if(! $hasPmDefaults && $canManageSheet)
                        <button
                            type="button"
                            wire:click="ensurePmColumns"
                            class="rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50"
                            title="Adds Owner, Due, and Priority columns"
                        >
                            + PM columns
                        </button>
                    @endif
                </div>
            </div>
        </div>

        {{-- Toolbar --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-2.5 sm:px-5">
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="addRow" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500/30">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m7-7H5"/></svg>
                    New item
                </button>
                <button type="button" wire:click="$set('showAddColumn', true)" class="inline-flex items-center gap-1.5 rounded-md border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    + Column
                </button>

                <div class="ml-1 inline-flex rounded-lg bg-gray-100 p-0.5" role="group" aria-label="Filter rows">
                    @foreach (['open' => 'Open', 'done' => 'Done', 'mine' => 'Mine', 'all' => 'All', 'archived' => 'Archived'] as $key => $label)
                        <button
                            type="button"
                            wire:click="setFilter('{{ $key }}')"
                            @disabled($key === 'mine' && ! $personCol)
                            class="rounded-md px-2.5 py-1 text-xs font-medium transition {{ $filter === $key ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800' }} {{ $key === 'mine' && ! $personCol ? 'opacity-40 cursor-not-allowed' : '' }}"
                        >{{ $label }}</button>
                    @endforeach
                </div>

                @if($sortColId && $sortCol)
                    <span class="inline-flex items-center gap-1 rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700">
                        {{ $sortCol->name }} {{ $sortDir === 'asc' ? '↑' : '↓' }}
                        <button type="button" wire:click="clearSort" class="text-blue-500 hover:text-blue-800" title="Clear sort">&times;</button>
                    </span>
                @endif
            </div>
            <span class="text-xs text-gray-400">{{ count($orderedRows) }} shown · {{ $columns->count() }} cols</span>
        </div>

        @if($showAddColumn)
        <div class="border-b border-gray-100 bg-slate-50 px-4 py-3 sm:px-5">
            <form wire:submit="addColumn" class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-500">Name</label>
                    <input type="text" wire:model="newColName" placeholder="e.g. Link" class="mt-1 w-44 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500" />
                    @error('newColName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500">Type</label>
                    <select wire:model.live="newColType" class="mt-1 w-36 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        @foreach(\App\Models\Sheet::columnTypes() as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if($newColType === 'status')
                <div>
                    <label class="block text-xs font-medium text-gray-500">Labels (comma-separated)</label>
                    <input type="text" wire:model="newColOptions" placeholder="To Do, In Progress, Stuck, Done" class="mt-1 w-64 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500" />
                </div>
                @endif
                <button type="submit" class="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700">Add</button>
                <button type="button" wire:click="$set('showAddColumn', false)" class="rounded-md px-3 py-1.5 text-sm font-medium text-gray-500 hover:text-gray-800">Cancel</button>
            </form>
        </div>
        @endif

        <div class="overflow-x-auto">
            <table class="min-w-full border-separate border-spacing-0 text-sm">
                <thead>
                    <tr>
                        <th class="sticky left-0 top-0 z-20 w-20 border-b border-r border-gray-200 bg-gray-50 px-3 py-2 text-left text-[11px] font-medium text-gray-400 shadow-[1px_0_0_rgba(0,0,0,0.04)]">#</th>
                        @forelse($columns as $col)
                            <th class="group/col min-w-[160px] border-b border-r border-gray-200 bg-gray-50 px-2 py-2 text-left" wire:key="col-{{ $col->id }}">
                                <div class="flex items-center gap-1">
                                    <input
                                        type="text"
                                        value="{{ $col->name }}"
                                        wire:change="renameColumn({{ $col->id }}, $event.target.value)"
                                        class="min-w-0 flex-1 rounded bg-transparent px-1 py-0.5 text-xs font-semibold text-gray-600 hover:bg-white focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-400"
                                        title="Rename column"
                                    />
                                    <button
                                        type="button"
                                        wire:click="sortBy({{ $col->id }})"
                                        class="shrink-0 rounded p-0.5 text-xs {{ $sortColId === $col->id ? 'text-blue-600' : 'text-gray-300 hover:text-gray-600' }}"
                                        title="Sort"
                                    >{{ $sortColId === $col->id ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' }}</button>
                                    <button type="button" wire:click="deleteColumn({{ $col->id }})" wire:confirm="Delete this column?" class="shrink-0 rounded p-0.5 text-gray-300 opacity-0 transition hover:text-rose-600 group-hover/col:opacity-100" title="Delete column">
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            </th>
                        @empty
                            <th class="border-b border-gray-200 bg-gray-50 px-4 py-2 text-left text-xs text-gray-400">No columns yet</th>
                        @endforelse
                    </tr>
                </thead>
                <tbody>
                    @forelse($orderedRows as $entry)
                        @php $row = $entry['row']; $depth = $entry['depth']; @endphp
                        <tr class="group hover:bg-slate-50/80 {{ $selectedRowId === $row->id ? 'bg-blue-50/50' : '' }}" wire:key="row-{{ $row->id }}">
                            <td class="sticky left-0 z-10 w-20 border-b border-r border-gray-100 bg-white px-2 py-1 text-xs text-gray-400 shadow-[1px_0_0_rgba(0,0,0,0.04)] group-hover:bg-slate-50/80 {{ $selectedRowId === $row->id ? 'bg-blue-50/50' : '' }}">
                                {{-- #: label when title column has the open control; otherwise clickable. --}}
                                <div class="flex items-center gap-0.5" style="padding-left: {{ $depth * 12 }}px">
                                    @if($depth > 0)<span class="text-gray-300">└</span>@endif
                                    @if($titleColId)
                                        <span class="inline-flex items-center rounded px-1 py-0.5 tabular-nums {{ $depth === 0 ? 'font-medium text-gray-500' : 'text-gray-400' }} {{ $selectedRowId === $row->id ? 'text-blue-700' : '' }}">
                                            {{ $entry['label'] }}
                                        </span>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="openRow({{ $row->id }})"
                                            class="inline-flex items-center rounded px-1 py-0.5 tabular-nums hover:bg-gray-100 hover:text-gray-700 {{ $depth === 0 ? 'font-medium text-gray-500' : 'text-gray-400' }} {{ $selectedRowId === $row->id ? 'text-blue-700' : '' }}"
                                            title="Open details"
                                        >{{ $entry['label'] }}</button>
                                    @endif
                                    @if($depth === 0 && $filter !== 'archived')
                                        <button
                                            type="button"
                                            wire:click="addSubRow({{ $row->id }})"
                                            class="inline-flex h-5 w-5 items-center justify-center rounded text-gray-400 opacity-0 transition hover:bg-blue-50 hover:text-blue-700 group-hover:opacity-100"
                                            title="Add subtask"
                                            aria-label="Add subtask"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m7-7H5"/></svg>
                                        </button>
                                    @endif
                                </div>
                            </td>
                            @foreach($columns as $col)
                                @php $cell = data_get($row->values, (string) $col->id); @endphp
                                <td class="border-b border-r border-gray-100 px-1 py-0.5 align-middle" wire:key="cell-{{ $row->id }}-{{ $col->id }}">
                                    @switch($col->type)
                                        @case('number')
                                            <input type="number" value="{{ $cell }}" wire:change="updateCell({{ $row->id }}, {{ $col->id }}, $event.target.value)" class="w-full rounded border border-transparent bg-transparent px-2 py-1.5 text-sm tabular-nums text-gray-900 hover:bg-gray-50 focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-400" />
                                            @break
                                        @case('date')
                                            @php
                                                $overdue = $cell && $cell < now()->toDateString() && ! ($primaryStatusCol && $isDoneLabel((string) data_get($row->values, (string) $primaryStatusCol->id, '')));
                                            @endphp
                                            <input type="date" value="{{ $cell }}" wire:change="updateCell({{ $row->id }}, {{ $col->id }}, $event.target.value)" class="w-full rounded border border-transparent bg-transparent px-2 py-1.5 text-sm {{ $overdue ? 'font-medium text-rose-600' : 'text-gray-900' }} hover:bg-gray-50 focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-400" />
                                            @break
                                        @case('checkbox')
                                            <div class="flex justify-center py-1">
                                                <input type="checkbox" @checked(filter_var($cell, FILTER_VALIDATE_BOOLEAN)) wire:change="updateCell({{ $row->id }}, {{ $col->id }}, $event.target.checked)" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                                            </div>
                                            @break
                                        @case('status')
                                            @php
                                                $opts = $col->options ?? [];
                                                $badge = $chipClassForColumn($col, $cell ? (string) $cell : null);
                                            @endphp
                                            <select wire:change="updateCell({{ $row->id }}, {{ $col->id }}, $event.target.value)" class="w-full max-w-[9.5rem] cursor-pointer border border-transparent px-2 py-1 text-left text-xs focus:outline-none {{ $badge }}">
                                                <option value="">—</option>
                                                @foreach($opts as $opt)
                                                    <option value="{{ $opt }}" @selected((string) $cell === (string) $opt)>{{ $opt }}</option>
                                                @endforeach
                                            </select>
                                            @break
                                        @case('person')
                                            @php $person = $cell ? $userById->get((int) $cell) : null; @endphp
                                            <div class="flex items-center gap-1.5 px-1">
                                                @if($person)
                                                    <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-600 text-[10px] font-semibold text-white" title="{{ $person->name }}">{{ $initials($person->name) }}</span>
                                                @else
                                                    <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-dashed border-gray-300 text-[10px] text-gray-300" title="Unassigned">+</span>
                                                @endif
                                                <select wire:change="updateCell({{ $row->id }}, {{ $col->id }}, $event.target.value)" class="min-w-0 flex-1 cursor-pointer rounded border border-transparent bg-transparent py-1 text-sm hover:bg-gray-50 focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-400 {{ $person ? 'text-gray-800' : 'text-gray-400' }}">
                                                    <option value="">Unassigned</option>
                                                    @foreach($users as $u)
                                                        <option value="{{ $u->id }}" @selected((string) $cell === (string) $u->id)>{{ $u->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            @break
                                        @case('link')
                                            <div class="flex items-center gap-1">
                                                <input type="url" value="{{ $cell }}" placeholder="https://" wire:change="updateCell({{ $row->id }}, {{ $col->id }}, $event.target.value)" class="w-full rounded border border-transparent bg-transparent px-2 py-1.5 text-sm text-blue-600 hover:bg-gray-50 focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-400" />
                                                @if($cell)
                                                    <a href="{{ $cell }}" target="_blank" rel="noopener" class="shrink-0 rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-blue-600" title="Open">↗</a>
                                                @endif
                                            </div>
                                            @break
                                        @default
                                            <div class="flex items-center gap-0.5">
                                                <input type="text" value="{{ $cell }}" placeholder="{{ $depth === 0 ? 'Item name' : 'Subtask' }}" wire:change="updateCell({{ $row->id }}, {{ $col->id }}, $event.target.value)" class="w-full rounded border border-transparent bg-transparent px-2 py-1.5 text-sm font-medium text-gray-900 placeholder:font-normal placeholder:text-gray-300 hover:bg-gray-50 focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-400" />
                                                @if($titleColId && $col->id === $titleColId)
                                                    @php
                                                        $hasDetail = ($row->comments_count ?? 0) > 0 || filled($row->description);
                                                        $isOpen = $selectedRowId === $row->id;
                                                    @endphp
                                                    <button
                                                        type="button"
                                                        wire:click="openRow({{ $row->id }})"
                                                        class="relative shrink-0 inline-flex h-7 w-7 items-center justify-center rounded-md transition
                                                            {{ $isOpen
                                                                ? 'bg-blue-100 text-blue-700 ring-1 ring-blue-200'
                                                                : ($hasDetail
                                                                    ? 'bg-slate-100 text-slate-700 hover:bg-blue-50 hover:text-blue-700'
                                                                    : 'bg-slate-50 text-slate-500 ring-1 ring-slate-200/80 hover:bg-slate-100 hover:text-slate-700') }}"
                                                        title="Open details & comments"
                                                        aria-label="Open details"
                                                    >
                                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5M7 4h10a2 2 0 012 2v14l-4-3H7a2 2 0 01-2-2V6a2 2 0 012-2z"/>
                                                        </svg>
                                                        @if(($row->comments_count ?? 0) > 0)
                                                            <span class="absolute -right-1 -top-1 inline-flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-blue-600 px-1 text-[9px] font-bold leading-none text-white">
                                                                {{ $row->comments_count > 9 ? '9+' : $row->comments_count }}
                                                            </span>
                                                        @elseif(filled($row->description))
                                                            <span class="absolute -right-0.5 -top-0.5 h-1.5 w-1.5 rounded-full bg-slate-500" title="Has description"></span>
                                                        @endif
                                                    </button>
                                                @endif
                                            </div>
                                    @endswitch
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ max(1, $columns->count() + 1) }}" class="px-4 py-14 text-center">
                                <div class="flex flex-col items-center gap-2 text-gray-400">
                                    <span class="text-sm">
                                        @if($filter === 'archived')
                                            No archived items.
                                        @elseif($filter !== 'all')
                                            No items match this filter.
                                        @else
                                            No items yet. Click <span class="font-medium text-gray-600">New item</span> to start.
                                        @endif
                                    </span>
                                    @if($filter !== 'all' && $filter !== 'open')
                                        <button type="button" wire:click="setFilter('open')" class="text-xs font-medium text-blue-600 hover:text-blue-800">Show open</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Item detail sidebar: stays open while switching items; grid stays clickable --}}
    @if($selectedRow)
        <aside
            class="fixed inset-y-0 right-0 z-40 flex w-full max-w-md flex-col border-l border-gray-200 bg-white shadow-[-8px_0_24px_rgba(15,23,42,0.08)] sm:max-w-lg"
            x-data
            x-on:keydown.escape.window="$wire.closeRow()"
            role="dialog"
            aria-label="Item details"
            wire:key="sheet-detail-shell"
        >
            <div
                class="flex h-full flex-col"
                wire:key="sheet-detail-{{ $selectedRow->id }}"
                wire:loading.class="opacity-60"
                wire:target="openRow"
            >
                <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-4 py-3">
                    <div class="min-w-0">
                        @php
                            $panelTitle = $titleCol
                                ? (string) data_get($selectedRow->values, (string) $titleCol->id, '')
                                : '';
                            if ($panelTitle === '') {
                                $panelTitle = 'Untitled item';
                            }
                        @endphp
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Item details</p>
                        <h3 class="mt-0.5 truncate text-base font-semibold text-gray-900" title="{{ $panelTitle }}">{{ $panelTitle }}</h3>
                        @if($selectedRow->isArchived())
                            <p class="mt-1 text-xs text-amber-700">Archived</p>
                        @endif
                    </div>
                    <button type="button" wire:click="closeRow" class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700" title="Close" aria-label="Close">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="flex-1 space-y-6 overflow-y-auto px-4 py-4">
                    @if(is_null($selectedRow->parent_id) && ! $selectedRow->isArchived())
                        <div>
                            <button
                                type="button"
                                wire:click="addSubRow({{ $selectedRow->id }})"
                                class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-gray-300 bg-gray-50 px-3 py-2 text-sm font-medium text-gray-700 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-800"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m7-7H5"/></svg>
                                Add subtask
                            </button>
                        </div>
                    @endif

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Description</label>
                        <p class="mt-0.5 text-xs text-gray-400">Context that doesn’t fit in the title — acceptance notes, links to intent, etc.</p>
                        <textarea
                            rows="5"
                            wire:model="descriptionDraft"
                            wire:blur="persistDescription"
                            class="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                            placeholder="Add more detail…"
                        ></textarea>
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-medium text-gray-700">Comments</h4>
                            <span class="text-xs text-gray-400">{{ $selectedRow->comments->count() }}</span>
                        </div>

                        <div class="mt-3 space-y-3">
                            @forelse($selectedRow->comments as $comment)
                                <div class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2.5" wire:key="sheet-comment-{{ $comment->id }}">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium text-gray-800">{{ $comment->user?->name ?? 'User' }}</p>
                                            <p class="text-[11px] text-gray-400">{{ $comment->created_at?->diffForHumans() }}</p>
                                        </div>
                                        @if(auth()->id() === $comment->user_id || auth()->user()?->is_admin)
                                            <button
                                                type="button"
                                                wire:click="deleteComment({{ $comment->id }})"
                                                wire:confirm="Delete this comment?"
                                                class="shrink-0 rounded p-0.5 text-gray-300 hover:text-rose-600"
                                                title="Delete comment"
                                            >
                                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        @endif
                                    </div>
                                    <p class="mt-1.5 whitespace-pre-wrap text-sm text-gray-700">{!! preg_replace('/@(\w+)/', '<span class="font-medium text-blue-600">@$1</span>', e($comment->body)) !!}</p>
                                </div>
                            @empty
                                <p class="rounded-lg border border-dashed border-gray-200 px-3 py-6 text-center text-xs text-gray-400">
                                    No comments yet. Capture decisions here so the title can stay short.
                                </p>
                            @endforelse
                        </div>

                        <form wire:submit="addComment" class="mt-3">
                            <textarea
                                wire:model="commentBody"
                                rows="3"
                                data-mention-sheet-id="{{ $sheetId }}"
                                class="js-mention-textarea w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                                placeholder="Write a comment… use @ to mention someone"
                            ></textarea>
                            @error('commentBody')
                                <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                            @enderror
                            <div class="mt-2 flex justify-end">
                                <button type="submit" class="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700">
                                    Comment
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="shrink-0 border-t border-gray-100 px-4 py-3">
                    <div class="flex flex-wrap items-center gap-2">
                        @if($selectedRow->isArchived())
                            <button type="button" wire:click="unarchiveRow({{ $selectedRow->id }})" class="rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-emerald-50 hover:text-emerald-800">Restore</button>
                        @else
                            <button
                                type="button"
                                wire:click="archiveRow({{ $selectedRow->id }})"
                                wire:confirm="Archive this item? You can find it later under Archived."
                                class="rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-amber-50 hover:text-amber-800"
                            >Archive</button>
                        @endif
                        <button
                            type="button"
                            wire:click="deleteRow({{ $selectedRow->id }})"
                            wire:confirm="{{ $selectedRow->parent_id ? 'Permanently delete this subtask?' : 'Permanently delete this item and its subtasks?' }}"
                            class="rounded-md px-3 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50"
                        >Delete</button>
                    </div>
                </div>
            </div>
        </aside>
    @endif
    @else
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-rose-700">Sheet not found.</div>
    @endif
</div>
