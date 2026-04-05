<?php

use App\Models\Board;
use App\Models\Item;
use Livewire\Component;

new class extends Component
{
    public int $boardId;

    public ?int $filterAssigneeId = null;

    public bool $filterUnassigned = false;

    public ?string $filterType = null;

    /** @var array<int> Filter by status/group IDs — only show items in these columns (empty = all) */
    public array $filterGroupIds = [];

    public string $sortColumn = 'position';

    public string $sortDirection = 'asc';

    /** Applied search term (used in query). Only set when user clicks Apply. */
    public string $filter = '';

    /** Current text in the search input (not applied until Apply is clicked). */
    public string $searchInput = '';

    public int $page = 1;

    public int $perPage = 20;

    /** @var array<int> */
    public array $selectedItemIds = [];

    public string $bulkAction = '';

    public function mount(int $boardId, ?int $filterAssigneeId = null, bool $filterUnassigned = false, ?string $filterType = null, array $filterGroupIds = []): void
    {
        $this->boardId = $boardId;
        $this->filterAssigneeId = $filterAssigneeId;
        $this->filterUnassigned = $filterUnassigned;
        $this->filterType = $filterType;
        $this->filterGroupIds = $filterGroupIds;
    }

    public function getBoardProperty(): ?Board
    {
        $board = Board::with(['columns' => fn ($q) => $q->orderBy('position')])
            ->where('id', $this->boardId)
            ->first();

        if (!$board) {
            return null;
        }

        // Get paginated items - build base query
        $itemsQuery = $board->items();
        
        // Apply filters first
        if ($this->filter !== '') {
            $itemsQuery->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($this->filter).'%']);
        }
        if ($this->filterUnassigned) {
            $itemsQuery->whereNull('assignee_id');
        } elseif ($this->filterAssigneeId !== null) {
            $itemsQuery->where('assignee_id', $this->filterAssigneeId);
        }
        if ($this->filterType !== null) {
            $itemsQuery->where('item_type', $this->filterType);
        }
        if (! empty($this->filterGroupIds)) {
            $itemsQuery->whereIn('group_id', $this->filterGroupIds);
        }
        
        // Apply sorting - clear any existing orderBy first
        $itemsQuery->reorder();
        
        if ($this->sortColumn === 'number') {
            // Ensure numerical sorting - INTEGER works in SQLite and MySQL
            $direction = strtoupper($this->sortDirection);
            $itemsQuery->orderByRaw("CAST(number AS INTEGER) {$direction}");
        } elseif ($this->sortColumn === 'name') {
            $direction = strtoupper($this->sortDirection);
            $itemsQuery->orderByRaw("LOWER(name) {$direction}");
        } elseif ($this->sortColumn === 'status') {
            // Join with groups table for status sorting
            $itemsQuery->leftJoin('groups', 'items.group_id', '=', 'groups.id')
                ->select('items.*');
            // Apply alphabetical sorting with NULL handling
            $direction = strtoupper($this->sortDirection);
            $itemsQuery->orderByRaw("CASE WHEN groups.name IS NULL THEN 1 ELSE 0 END")
                ->orderByRaw("LOWER(COALESCE(groups.name, '')) {$direction}");
        } elseif ($this->sortColumn === 'type') {
            $itemsQuery->orderBy('item_type', $this->sortDirection);
        } elseif ($this->sortColumn === 'assignee') {
            // Join with users table for assignee sorting
            $itemsQuery->leftJoin('users', 'items.assignee_id', '=', 'users.id')
                ->select('items.*');
            // Apply alphabetical sorting with NULL handling
            $direction = strtoupper($this->sortDirection);
            $itemsQuery->orderByRaw("CASE WHEN users.name IS NULL THEN 1 ELSE 0 END")
                ->orderByRaw("LOWER(COALESCE(users.name, '')) {$direction}");
        } elseif ($this->sortColumn === 'updated_at') {
            $itemsQuery->orderBy('updated_at', $this->sortDirection);
        } elseif ($this->sortColumn === 'priority') {
            $itemsQuery->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END " . $this->sortDirection);
        } elseif ($this->sortColumn === 'due_at') {
            $direction = strtoupper($this->sortDirection);
            $itemsQuery->orderByRaw("CASE WHEN due_at IS NULL THEN 1 ELSE 0 END, due_at {$direction}");
        } else {
            $itemsQuery->orderBy('position', 'asc');
        }
        
        // Build count query separately (without select and with)
        $countQuery = $board->items();
        if ($this->filter !== '') {
            $countQuery->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($this->filter).'%']);
        }
        if ($this->filterUnassigned) {
            $countQuery->whereNull('assignee_id');
        } elseif ($this->filterAssigneeId !== null) {
            $countQuery->where('assignee_id', $this->filterAssigneeId);
        }
        if ($this->filterType !== null) {
            $countQuery->where('item_type', $this->filterType);
        }
        if (! empty($this->filterGroupIds)) {
            $countQuery->whereIn('group_id', $this->filterGroupIds);
        }
        $totalItems = $countQuery->count();
        
        // Eager load relationships AFTER sorting is applied
        $itemsQuery->with(['group', 'assignee', 'creator', 'activities' => function ($q) {
            $q->with('user')->orderByDesc('created_at')->limit(1);
        }])->withCount('children');
        
        // Paginate only if more than 20 items
        if ($totalItems > 20) {
            $board->setRelation('items', $itemsQuery->skip(($this->page - 1) * $this->perPage)->take($this->perPage)->get());
            $board->setAttribute('items_total', $totalItems);
            $board->setAttribute('items_per_page', $this->perPage);
            $board->setAttribute('items_current_page', $this->page);
            $board->setAttribute('items_last_page', (int) ceil($totalItems / $this->perPage));
        } else {
            $board->setRelation('items', $itemsQuery->get());
            $board->setAttribute('items_total', $totalItems);
            $board->setAttribute('items_per_page', null);
            $board->setAttribute('items_current_page', 1);
            $board->setAttribute('items_last_page', 1);
        }

        return $board;
    }

    public function goToPage(int $page): void
    {
        $this->page = $page;
    }

    public function sortBy(string $column): void
    {
        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }
        $this->page = 1; // Reset to first page when sorting changes
    }

    public function applySearch(): void
    {
        $this->filter = $this->searchInput;
        $this->page = 1; // Reset to first page when search changes
    }

    public function resetSearch(): void
    {
        $this->filter = '';
        $this->searchInput = '';
        $this->page = 1; // Reset to first page when search resets
    }

    /**
     * @param  array<int|string>  $visibleIds
     */
    public function toggleSelectAllVisible(array $visibleIds): void
    {
        $visibleIds = array_values(array_unique(array_map('intval', $visibleIds)));
        if ($visibleIds === []) {
            return;
        }

        $selectedIds = array_values(array_unique(array_map('intval', $this->selectedItemIds)));
        $allVisibleSelected = count(array_intersect($visibleIds, $selectedIds)) === count($visibleIds);

        if ($allVisibleSelected) {
            $this->selectedItemIds = array_values(array_diff($selectedIds, $visibleIds));

            return;
        }

        $this->selectedItemIds = array_values(array_unique([...$selectedIds, ...$visibleIds]));
    }

    public function deleteSelected(): void
    {
        $ids = array_values(array_unique(array_map('intval', $this->selectedItemIds)));
        if ($ids === []) {
            return;
        }

        Item::query()
            ->where('board_id', $this->boardId)
            ->whereIn('id', $ids)
            ->get()
            ->each
            ->delete();

        $deletedCount = count($ids);
        $this->selectedItemIds = [];
        $this->page = 1;
        unset($this->board);
        session()->flash('success', $deletedCount === 1 ? '1 item deleted.' : $deletedCount.' items deleted.');
    }

    public function applyBulkAction(): void
    {
        $ids = array_values(array_unique(array_map('intval', $this->selectedItemIds)));
        if ($ids === [] || $this->bulkAction === '') {
            return;
        }

        if ($this->bulkAction === 'delete') {
            $this->deleteSelected();
            $this->bulkAction = '';

            return;
        }

        if (str_starts_with($this->bulkAction, 'status:')) {
            $groupId = (int) substr($this->bulkAction, 7);
            $group = Board::query()
                ->whereKey($this->boardId)
                ->first()?->groups()
                ->whereKey($groupId)
                ->first();

            if (! $group) {
                return;
            }

            Item::query()
                ->where('board_id', $this->boardId)
                ->whereIn('id', $ids)
                ->update(['group_id' => $groupId]);

            $groupName = $group->name ?? 'selected status';
            $updatedCount = count($ids);
            $this->selectedItemIds = [];
            $this->bulkAction = '';
            $this->page = 1;
            unset($this->board);
            session()->flash('success', $updatedCount === 1 ? '1 item moved to '.$groupName.'.' : $updatedCount.' items moved to '.$groupName.'.');
        }
    }

    public function clearSelection(): void
    {
        $this->selectedItemIds = [];
        $this->bulkAction = '';
    }
};
?>

@php
    $board = $this->board;
    $visibleItemIds = $board?->items->pluck('id')->map(fn ($id) => (int) $id)->values()->all() ?? [];
    $selectedVisibleCount = count(array_intersect($visibleItemIds, array_map('intval', $selectedItemIds)));
    $allVisibleSelected = ! empty($visibleItemIds) && $selectedVisibleCount === count($visibleItemIds);
@endphp
<div>
<div class="rounded-lg border border-gray-200 bg-white shadow-sm">
@if($board)
    {{-- Minimal toolbar: add item + search --}}
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
        <form action="{{ route('items.store', $board) }}" method="POST" class="flex items-center gap-2">
            @csrf
            <input type="hidden" name="view" value="table" />
            <input type="text" name="name" placeholder="New item name" required class="w-56 rounded border border-gray-200 px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-gray-400 focus:outline-none focus:ring-1 focus:ring-gray-400" />
            <button type="submit" class="rounded border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Add</button>
        </form>
        <div class="flex items-center gap-2">
            @if(!empty($visibleItemIds))
                <button
                    type="button"
                    wire:click="toggleSelectAllVisible({{ \Illuminate\Support\Js::from($visibleItemIds) }})"
                    class="rounded border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                    {{ $allVisibleSelected ? 'Unselect all' : 'Select all' }}
                </button>
            @endif
            @if(!empty($selectedItemIds))
                <button
                    type="button"
                    wire:click="clearSelection"
                    class="inline-flex h-9 w-9 items-center justify-center rounded border border-gray-300 bg-white text-gray-500 hover:bg-gray-50 hover:text-gray-700"
                    title="Clear selected items"
                    aria-label="Clear selected items"
                >
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M4.22 4.22a.75.75 0 0 1 1.06 0L10 8.94l4.72-4.72a.75.75 0 1 1 1.06 1.06L11.06 10l4.72 4.72a.75.75 0 1 1-1.06 1.06L10 11.06l-4.72 4.72a.75.75 0 1 1-1.06-1.06L8.94 10 4.22 5.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                    </svg>
                </button>
                <form
                    wire:submit="applyBulkAction"
                    x-data="{ action: @entangle('bulkAction') }"
                    onsubmit="
                        const action = this.querySelector('select[name=bulk-action]')?.value || '';
                        if (!action) return false;
                        if (action === 'delete') return confirm('Delete all selected items?');
                        if (action.startsWith('status:')) {
                            const label = this.querySelector('select[name=bulk-action] option:checked')?.textContent?.trim() || 'the selected status';
                            return confirm('Change the selected items to ' + label + '?');
                        }
                        return false;
                    "
                    class="flex items-center gap-2"
                >
                    <select
                        name="bulk-action"
                        wire:model.live="bulkAction"
                        class="rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-gray-400 focus:outline-none focus:ring-1 focus:ring-gray-400"
                    >
                        <option value="">Bulk actions</option>
                        <option value="delete">Delete selected</option>
                        @foreach($board->groups as $group)
                            <option value="status:{{ $group->id }}">Change status to {{ $group->name }}</option>
                        @endforeach
                    </select>
                    <button
                        type="submit"
                        @disabled($bulkAction === '')
                        class="rounded border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:pointer-events-none disabled:opacity-50"
                    >
                        Apply ({{ count($selectedItemIds) }})
                    </button>
                </form>
            @endif
            <input type="text" wire:model.live.debounce.300ms="searchInput" placeholder="Search" class="w-44 rounded border border-gray-200 px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-gray-400 focus:outline-none focus:ring-1 focus:ring-gray-400" />
            @if($filter !== '')
                <button type="button" wire:click="resetSearch" class="rounded border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">Reset</button>
            @elseif($searchInput !== '')
                <button type="button" wire:click="applySearch" class="rounded border border-gray-300 bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Apply</button>
            @endif
        </div>
    </div>

    <div class="js-list-view-table mx-4 my-4 max-h-[calc(100vh-22rem)] overflow-x-auto overflow-y-auto rounded border border-gray-200 bg-white p-2 pb-8">
        <table class="min-w-full">
            <thead>
                <tr class="border-b border-gray-100">
                    <th class="w-12 px-2 py-2 text-left text-xs font-medium text-gray-500">
                        <input
                            type="checkbox"
                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                            wire:click="toggleSelectAllVisible({{ \Illuminate\Support\Js::from($visibleItemIds) }})"
                            @checked($allVisibleSelected)
                            aria-label="Select all visible items"
                        />
                    </th>
                    <th class="w-14 px-2 py-2 text-left text-xs font-medium text-gray-500">
                        <button type="button" wire:click="sortBy('number')" class="font-medium text-gray-600 hover:text-gray-900">
                            #
                            @if($this->sortColumn === 'number')
                                <span class="text-gray-400">{{ $this->sortDirection === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </button>
                    </th>
                    <th class="px-2 py-2 text-left text-xs font-medium text-gray-500">
                        <button type="button" wire:click="sortBy('name')" class="flex items-center gap-1 font-medium text-gray-600 hover:text-gray-900">
                            @if($this->sortColumn === 'name')
                                <span class="text-gray-400">{{ $this->sortDirection === 'asc' ? '↑' : '↓' }}</span>
                            @else
                                <span class="text-gray-300">↕</span>
                            @endif
                            Name
                        </button>
                    </th>
                    <th class="px-2 py-2 text-left text-xs font-medium text-gray-500">
                        <button type="button" wire:click="sortBy('status')" class="font-medium text-gray-600 hover:text-gray-900">
                            Status
                            @if($this->sortColumn === 'status')
                                <span class="text-gray-400">{{ $this->sortDirection === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </button>
                    </th>
                    <th class="w-20 px-2 py-2 text-left text-xs font-medium text-gray-500">
                        <button type="button" wire:click="sortBy('type')" class="font-medium text-gray-600 hover:text-gray-900">
                            Type
                            @if($this->sortColumn === 'type')
                                <span class="text-gray-400">{{ $this->sortDirection === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </button>
                    </th>
                    <th class="w-20 px-2 py-2 text-left text-xs font-medium text-gray-500">
                        <button type="button" wire:click="sortBy('priority')" class="font-medium text-gray-600 hover:text-gray-900">
                            Priority
                            @if($this->sortColumn === 'priority')
                                <span class="text-gray-400">{{ $this->sortDirection === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </button>
                    </th>
                    <th class="w-24 px-2 py-2 text-left text-xs font-medium text-gray-500">
                        <button type="button" wire:click="sortBy('due_at')" class="font-medium text-gray-600 hover:text-gray-900">
                            Due
                            @if($this->sortColumn === 'due_at')
                                <span class="text-gray-400">{{ $this->sortDirection === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </button>
                    </th>
                    <th class="px-2 py-2 text-left text-xs font-medium text-gray-500">
                        <button type="button" wire:click="sortBy('assignee')" class="font-medium text-gray-600 hover:text-gray-900">
                            Assignee
                            @if($this->sortColumn === 'assignee')
                                <span class="text-gray-400">{{ $this->sortDirection === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </button>
                    </th>
                    <th class="px-2 py-2 text-left text-xs font-medium text-gray-500">
                        <button type="button" wire:click="sortBy('updated_at')" class="font-medium text-gray-600 hover:text-gray-900">
                            Last Updated
                            @if($this->sortColumn === 'updated_at')
                                <span class="text-gray-400">{{ $this->sortDirection === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </button>
                    </th>
                    <th class="px-2 py-2 text-left text-xs font-medium text-gray-500">Updated By</th>
                    <th class="w-20 px-2 py-2 text-right text-xs font-medium text-gray-500">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($board->items as $item)
                    <tr class="border-b border-gray-50 hover:bg-gray-50/80 transition-colors">
                        <td class="px-2 py-2 align-top">
                            <input
                                type="checkbox"
                                value="{{ $item->id }}"
                                wire:model.live="selectedItemIds"
                                class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                aria-label="Select item #{{ $item->number }}"
                            />
                        </td>
                        <td class="px-2 py-2 text-sm text-gray-500">#{{ $item->number }}</td>
                        <td class="px-2 py-2">
                            <div class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
                                @if($item->parent_id)<span class="text-gray-400" title="Sub-item">↳</span>@endif
                                <a href="{{ route('boards.show.item', ['board' => $board->id, 'item' => $item->number, 'view' => 'table']) }}" class="text-sm font-medium text-gray-900 hover:text-blue-600">{{ $item->name }}</a>
                                @if($item->dev_tag && ($t = \App\Models\Item::devTagLabel($item->dev_tag)))
                                    <span class="inline-flex rounded-full bg-violet-100 px-1.5 py-0.5 text-[10px] font-medium text-violet-800">{{ $t }}</span>
                                @endif
                                @if(($item->children_count ?? 0) > 0)
                                    <span class="text-[10px] text-gray-500">{{ $item->children_count }} sub</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-2 py-2 text-sm text-gray-600">{{ $item->group?->name ?? '—' }}</td>
                        <td class="px-2 py-2">
                            @if($item->item_type === 'bug')
                                <span class="text-xs text-red-600">Bug</span>
                            @else
                                <span class="text-xs text-amber-600">Task</span>
                            @endif
                        </td>
                        <td class="px-2 py-2 text-sm text-gray-600">{{ ucfirst($item->priority ?? 'medium') }}</td>
                        <td class="px-2 py-2 text-sm text-gray-600">
                            @if($item->due_at)
                                <span class="{{ $item->isOverdue() ? 'text-red-600' : '' }}">{{ $item->due_at->format('M j, Y') }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-2 py-2 text-sm text-gray-600">
                            @if($item->assignee)
                                @php
                                    $assignee = $item->assignee;
                                    $photoUrl = route('api.users.photo', $assignee);
                                    $nameParts = explode(' ', trim($assignee->name));
                                    $initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr($nameParts[count($nameParts) - 1], 0, 1) : ''));
                                @endphp
                                <div class="flex items-center gap-2">
                                    <div class="relative h-6 w-6 shrink-0 overflow-hidden rounded-full bg-gray-300">
                                        <img src="{{ $photoUrl }}" alt="{{ $assignee->name }}" class="h-full w-full object-cover" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
                                        <div class="hidden h-full w-full items-center justify-center bg-gray-400 text-xs font-medium text-white">
                                            {{ $initials }}
                                        </div>
                                    </div>
                                    <span>{{ $assignee->name }}</span>
                                </div>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-2 py-2 text-sm text-gray-600">
                            @if($item->updated_at)
                                {{ $item->updated_at->format('M d, Y H:i') }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-2 py-2 text-sm text-gray-600">
                            @php
                                $lastActivity = $item->activities->first();
                                $updatedBy = $lastActivity?->user ?? $item->creator;
                            @endphp
                            @if($updatedBy)
                                @php
                                    $photoUrl = route('api.users.photo', $updatedBy);
                                    $nameParts = explode(' ', trim($updatedBy->name));
                                    $initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr($nameParts[count($nameParts) - 1], 0, 1) : ''));
                                @endphp
                                <div class="flex items-center gap-2">
                                    <div class="relative h-6 w-6 shrink-0 overflow-hidden rounded-full bg-gray-300">
                                        <img src="{{ $photoUrl }}" alt="{{ $updatedBy->name }}" class="h-full w-full object-cover" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
                                        <div class="hidden h-full w-full items-center justify-center bg-gray-400 text-xs font-medium text-white">
                                            {{ $initials }}
                                        </div>
                                    </div>
                                    <span>{{ $updatedBy->name }}</span>
                                </div>
                            @else
                                —
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-2 py-2 text-right">
                            <div class="inline-flex items-center">
                                <a href="{{ route('boards.show.item', ['board' => $board->id, 'item' => $item->number, 'view' => 'table']) }}" class="rounded border border-green-400 px-2 py-1 text-xs text-gray-700 hover:border-green-500 hover:text-gray-900">View</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="px-2 py-16 text-center text-sm text-gray-400">No items. Add one above.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($board->items_per_page !== null && $board->items_last_page > 1)
        <div class="flex items-center justify-between border-t border-gray-100 px-4 py-3">
            <div class="text-sm text-gray-600">
                Showing {{ (($board->items_current_page - 1) * $board->items_per_page) + 1 }} to {{ min($board->items_current_page * $board->items_per_page, $board->items_total) }} of {{ $board->items_total }} items
            </div>
            <div class="flex items-center gap-2">
                @if($board->items_current_page > 1)
                    <button type="button" wire:click="goToPage({{ $board->items_current_page - 1 }})" class="rounded border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Previous</button>
                @endif
                @for($i = max(1, $board->items_current_page - 2); $i <= min($board->items_last_page, $board->items_current_page + 2); $i++)
                    <button type="button" wire:click="goToPage({{ $i }})" class="rounded border px-3 py-1.5 text-sm {{ $i === $board->items_current_page ? 'border-gray-400 bg-gray-100 text-gray-900' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">{{ $i }}</button>
                @endfor
                @if($board->items_current_page < $board->items_last_page)
                    <button type="button" wire:click="goToPage({{ $board->items_current_page + 1 }})" class="rounded border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Next</button>
                @endif
            </div>
        </div>
    @endif
@else
    <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-red-700">Board not found.</div>
@endif
</div>
{{-- Explicit white margin below the card so it's always visible --}}
<div class="min-h-[min(400px,35vh)] shrink-0" aria-hidden="true"></div>
</div>
