<?php

use App\Models\Board;
use Livewire\Component;

new class extends Component
{
    public int $boardId;

    public ?int $filterAssigneeId = null;

    public bool $filterUnassigned = false;

    public ?string $filterType = null;

    public string $sortColumn = 'position';

    public string $sortDirection = 'asc';

    /** Applied search term (used in query). Only set when user clicks Apply. */
    public string $filter = '';

    /** Current text in the search input (not applied until Apply is clicked). */
    public string $searchInput = '';

    public int $page = 1;

    public int $perPage = 20;

    public function mount(int $boardId, ?int $filterAssigneeId = null, bool $filterUnassigned = false, ?string $filterType = null): void
    {
        $this->boardId = $boardId;
        $this->filterAssigneeId = $filterAssigneeId;
        $this->filterUnassigned = $filterUnassigned;
        $this->filterType = $filterType;
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
        
        // Apply sorting - clear any existing orderBy first
        $itemsQuery->reorder();
        
        if ($this->sortColumn === 'number') {
            // Ensure numerical sorting - use explicit integer ordering
            $direction = strtoupper($this->sortDirection);
            $itemsQuery->orderByRaw("CAST(number AS UNSIGNED) {$direction}");
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
        $totalItems = $countQuery->count();
        
        // Eager load relationships AFTER sorting is applied
        $itemsQuery->with(['group', 'assignee', 'creator', 'activities' => function ($q) {
            $q->with('user')->orderByDesc('created_at')->limit(1);
        }]);
        
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
};
?>

@php
    $board = $this->board;
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
                    <th class="w-28 px-2 py-2 text-right text-xs font-medium text-gray-500">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($board->items as $item)
                    <tr class="border-b border-gray-50 hover:bg-gray-50/80 transition-colors">
                        <td class="px-2 py-2 text-sm text-gray-500">#{{ $item->number }}</td>
                        <td class="px-2 py-2">
                            <a href="{{ route('boards.show.item', ['board' => $board->id, 'item' => $item->number, 'view' => 'table']) }}" class="text-sm font-medium text-gray-900 hover:text-blue-600">{{ $item->name }}</a>
                        </td>
                        <td class="px-2 py-2 text-sm text-gray-600">{{ $item->group?->name ?? '—' }}</td>
                        <td class="px-2 py-2">
                            @if($item->item_type === 'bug')
                                <span class="text-xs text-red-600">Bug</span>
                            @else
                                <span class="text-xs text-amber-600">Task</span>
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
                            <div class="inline-flex items-center gap-2">
                                <a href="{{ route('boards.show.item', ['board' => $board->id, 'item' => $item->number, 'view' => 'table']) }}" class="rounded border border-green-400 px-2 py-1 text-xs text-gray-700 hover:border-green-500 hover:text-gray-900">View</a>
                                <form action="{{ route('items.destroy', [$board, $item]) }}" method="POST" class="inline" onsubmit="return confirm('Delete this item?');">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="view" value="table" />
                                    <button type="submit" class="text-xs text-gray-500 hover:text-red-600">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-2 py-16 text-center text-sm text-gray-400">No items. Add one above.</td>
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
