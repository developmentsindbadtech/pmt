<?php

use App\Models\Sheet;
use App\Models\SheetColumn;
use App\Models\SheetRow;
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

    public function mount(int $sheetId): void
    {
        $this->sheetId = $sheetId;

        $user = auth()->user();
        if ($user && ! $user->is_admin) {
            $hasAccess = \App\Models\Sheet::whereKey($sheetId)
                ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
                ->exists();
            abort_unless($hasAccess, 403);
        }
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
        return SheetRow::where('sheet_id', $this->sheetId)->orderBy('position')->orderBy('id')->get();
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
                $options = ['To Do', 'In Progress', 'Done'];
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
    }
};
?>

@php
    $sheet = $this->sheet;
    $columns = $this->columns;
    $rows = $this->rows;
    $users = $this->users;

    // Build a tree-ordered list: each top-level row followed by its subtasks.
    // When a column sort is active, sort by that column's value; otherwise by position.
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
    foreach ($sortRows($byParent->get(0, collect())) as $top) {
        $n++;
        $orderedRows[] = ['row' => $top, 'depth' => 0, 'label' => (string) $n];
        $k = 0;
        foreach ($sortRows($byParent->get($top->id, collect())) as $child) {
            $k++;
            $orderedRows[] = ['row' => $child, 'depth' => 1, 'label' => $n.'.'.$k];
        }
    }

    $statusPalette = [
        'bg-gray-100 text-gray-700',
        'bg-blue-100 text-blue-700',
        'bg-green-100 text-green-700',
        'bg-amber-100 text-amber-700',
        'bg-purple-100 text-purple-700',
        'bg-red-100 text-red-700',
        'bg-teal-100 text-teal-700',
        'bg-pink-100 text-pink-700',
    ];
@endphp

<div>
    @if($sheet)
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm ring-1 ring-black/5">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 bg-gray-50/60 px-4 py-2.5">
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="addRow" class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500/40">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m7-7H5"/></svg>
                    New row
                </button>
                <button type="button" wire:click="$set('showAddColumn', true)" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                    <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m7-7H5"/></svg>
                    Add column
                </button>
                @if($sortColId && $sortCol)
                    <span class="inline-flex items-center gap-1.5 rounded-md border border-blue-200 bg-blue-50 px-2.5 py-1.5 text-xs font-medium text-blue-700">
                        Sorted by {{ $sortCol->name }} {{ $sortDir === 'asc' ? '↑' : '↓' }}
                        <button type="button" wire:click="clearSort" class="rounded p-0.5 text-blue-500 hover:bg-blue-100 hover:text-blue-700" title="Clear sorting">&times;</button>
                    </span>
                @endif
            </div>
            <span class="text-xs font-medium text-gray-400">{{ $rows->count() }} {{ \Illuminate\Support\Str::plural('row', $rows->count()) }} · {{ $columns->count() }} {{ \Illuminate\Support\Str::plural('column', $columns->count()) }}</span>
        </div>

        @if($showAddColumn)
        <div class="border-b border-gray-100 bg-blue-50/40 px-4 py-3">
            <form wire:submit="addColumn" class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-500">Column name</label>
                    <input type="text" wire:model="newColName" placeholder="e.g. Owner" class="mt-1 w-48 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500" />
                    @error('newColName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500">Type</label>
                    <select wire:model.live="newColType" class="mt-1 w-40 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                        @foreach(\App\Models\Sheet::columnTypes() as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if($newColType === 'status')
                <div>
                    <label class="block text-xs font-medium text-gray-500">Options (comma-separated)</label>
                    <input type="text" wire:model="newColOptions" placeholder="To Do, In Progress, Done" class="mt-1 w-64 rounded-md border border-gray-300 px-2.5 py-1.5 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500" />
                </div>
                @endif
                <button type="submit" class="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-blue-700">Add column</button>
                <button type="button" wire:click="$set('showAddColumn', false)" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-50">Cancel</button>
            </form>
        </div>
        @endif

        <div class="overflow-x-auto">
            <table class="min-w-full border-separate border-spacing-0 text-sm">
                <thead>
                    <tr>
                        <th class="sticky left-0 top-0 z-20 w-32 border-b border-r border-gray-200 bg-gray-100 px-3 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 shadow-[1px_0_0_rgba(0,0,0,0.04)]">#</th>
                        @forelse($columns as $col)
                            <th class="group/col min-w-[180px] border-b border-r border-gray-200 bg-gray-100 px-2 py-2 text-left" wire:key="col-{{ $col->id }}">
                                <div class="flex items-center gap-1">
                                    <input
                                        type="text"
                                        value="{{ $col->name }}"
                                        wire:change="renameColumn({{ $col->id }}, $event.target.value)"
                                        class="min-w-0 flex-1 rounded bg-transparent px-1 py-0.5 text-[11px] font-semibold uppercase tracking-wider text-gray-600 hover:bg-white/70 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-400"
                                        title="Rename column"
                                    />
                                    <button
                                        type="button"
                                        wire:click="sortBy({{ $col->id }})"
                                        class="shrink-0 rounded p-0.5 text-sm leading-none {{ $sortColId === $col->id ? 'text-blue-600' : 'text-gray-400 hover:bg-white hover:text-gray-700' }}"
                                        title="Sort by {{ $col->name }}"
                                    >{{ $sortColId === $col->id ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' }}</button>
                                    <span class="shrink-0 rounded bg-gray-200/70 px-1 py-0.5 text-[9px] font-medium uppercase tracking-wide text-gray-500">{{ $col->type }}</span>
                                    <button type="button" wire:click="deleteColumn({{ $col->id }})" wire:confirm="Delete this column?" class="shrink-0 rounded p-0.5 text-gray-300 opacity-0 transition hover:bg-white hover:text-red-600 group-hover/col:opacity-100" title="Delete column">
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            </th>
                        @empty
                            <th class="border-b border-gray-200 bg-gray-100 px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-400">No columns yet — add one</th>
                        @endforelse
                    </tr>
                </thead>
                <tbody>
                    @forelse($orderedRows as $entry)
                        @php $row = $entry['row']; $depth = $entry['depth']; @endphp
                        <tr class="group transition-colors hover:bg-blue-50/40" wire:key="row-{{ $row->id }}">
                            <td class="sticky left-0 z-10 w-32 border-b border-r border-gray-100 bg-white px-3 py-1.5 text-xs text-gray-400 shadow-[1px_0_0_rgba(0,0,0,0.04)] group-hover:bg-blue-50/40">
                                <div class="flex items-center gap-1.5" style="padding-left: {{ $depth * 16 }}px">
                                    @if($depth > 0)<span class="text-gray-300" title="Subtask">↳</span>@endif
                                    <span class="tabular-nums {{ $depth === 0 ? 'font-semibold text-gray-600' : 'text-gray-400' }}">{{ $entry['label'] }}</span>
                                    @if($depth === 0)
                                        <button type="button" wire:click="addSubRow({{ $row->id }})" class="inline-flex shrink-0 items-center gap-0.5 rounded-md border border-blue-200 bg-blue-50 px-1.5 py-0.5 text-[11px] font-medium text-blue-600 transition hover:border-blue-300 hover:bg-blue-100" title="Add a subtask under this row">
                                            <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m7-7H5"/></svg>
                                            Subtask
                                        </button>
                                    @endif
                                    <button type="button" wire:click="deleteRow({{ $row->id }})" wire:confirm="@if($depth === 0)Delete this row and its subtasks?@else Delete this subtask?@endif" class="shrink-0 rounded p-0.5 text-gray-300 opacity-0 transition hover:bg-white hover:text-red-600 group-hover:opacity-100" title="Delete row">
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            </td>
                            @foreach($columns as $col)
                                @php $cell = data_get($row->values, (string) $col->id); @endphp
                                <td class="border-b border-r border-gray-100 px-1 py-1 align-middle" wire:key="cell-{{ $row->id }}-{{ $col->id }}">
                                    @switch($col->type)
                                        @case('number')
                                            <input type="number" value="{{ $cell }}" wire:change="updateCell({{ $row->id }}, {{ $col->id }}, $event.target.value)" class="w-full rounded-md border border-transparent bg-transparent px-2 py-1.5 text-sm tabular-nums text-gray-900 transition hover:border-gray-200 hover:bg-gray-50 focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-400" />
                                            @break
                                        @case('date')
                                            <input type="date" value="{{ $cell }}" wire:change="updateCell({{ $row->id }}, {{ $col->id }}, $event.target.value)" class="w-full rounded-md border border-transparent bg-transparent px-2 py-1.5 text-sm text-gray-900 transition hover:border-gray-200 hover:bg-gray-50 focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-400" />
                                            @break
                                        @case('checkbox')
                                            <div class="flex justify-center">
                                                <input type="checkbox" @checked(filter_var($cell, FILTER_VALIDATE_BOOLEAN)) wire:change="updateCell({{ $row->id }}, {{ $col->id }}, $event.target.checked)" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                                            </div>
                                            @break
                                        @case('status')
                                            @php
                                                $opts = $col->options ?? [];
                                                $idx = array_search($cell, $opts, true);
                                                $badge = $idx === false ? 'bg-gray-50 text-gray-400' : $statusPalette[$idx % count($statusPalette)];
                                            @endphp
                                            <select wire:change="updateCell({{ $row->id }}, {{ $col->id }}, $event.target.value)" class="w-full cursor-pointer rounded-full border-0 px-2.5 py-1 text-center text-xs font-semibold transition focus:outline-none focus:ring-2 focus:ring-blue-400/50 {{ $badge }}">
                                                <option value="">—</option>
                                                @foreach($opts as $opt)
                                                    <option value="{{ $opt }}" @selected((string) $cell === (string) $opt)>{{ $opt }}</option>
                                                @endforeach
                                            </select>
                                            @break
                                        @case('person')
                                            <select wire:change="updateCell({{ $row->id }}, {{ $col->id }}, $event.target.value)" class="w-full cursor-pointer rounded-md border border-transparent bg-transparent px-2 py-1.5 text-sm text-gray-900 transition hover:border-gray-200 hover:bg-gray-50 focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-400">
                                                <option value="">—</option>
                                                @foreach($users as $u)
                                                    <option value="{{ $u->id }}" @selected((string) $cell === (string) $u->id)>{{ $u->name }}</option>
                                                @endforeach
                                            </select>
                                            @break
                                        @case('link')
                                            <div class="flex items-center gap-1">
                                                <input type="url" value="{{ $cell }}" placeholder="https://" wire:change="updateCell({{ $row->id }}, {{ $col->id }}, $event.target.value)" class="w-full rounded-md border border-transparent bg-transparent px-2 py-1.5 text-sm text-blue-600 transition hover:border-gray-200 hover:bg-gray-50 focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-400" />
                                                @if($cell)
                                                    <a href="{{ $cell }}" target="_blank" rel="noopener" class="shrink-0 rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-blue-600" title="Open link">↗</a>
                                                @endif
                                            </div>
                                            @break
                                        @default
                                            <input type="text" value="{{ $cell }}" placeholder="—" wire:change="updateCell({{ $row->id }}, {{ $col->id }}, $event.target.value)" class="w-full rounded-md border border-transparent bg-transparent px-2 py-1.5 text-sm text-gray-900 transition placeholder:text-gray-300 hover:border-gray-200 hover:bg-gray-50 focus:border-blue-400 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-400" />
                                    @endswitch
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ max(1, $columns->count() + 1) }}" class="px-4 py-16 text-center">
                                <div class="flex flex-col items-center gap-2 text-gray-400">
                                    <svg class="h-8 w-8 text-gray-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M3 14h18M12 4v16M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/></svg>
                                    <span class="text-sm">No rows yet. Click <span class="font-medium text-gray-600">New row</span> to start.</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @else
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700">Sheet not found.</div>
    @endif
</div>
