<?php

use App\Models\Board;
use App\Models\Group;
use App\Models\Item;
use Livewire\Component;

new class extends Component
{
    public int $boardId;

    public ?int $filterAssigneeId = null;

    public bool $filterUnassigned = false;

    public ?string $filterType = null;

    /** @var array<int> Filter by status/group IDs — only show these columns (empty = all) */
    public array $filterGroupIds = [];

    /** From parent; parent uses wire:key including this value so each change remounts with correct filter. */
    public string $filterSearch = '';

    /** active | archived */
    public string $itemVisibility = 'active';

    public bool $showDone = false;

    public function mount(
        int $boardId,
        ?int $filterAssigneeId = null,
        bool $filterUnassigned = false,
        ?string $filterType = null,
        array $filterGroupIds = [],
        string $filterSearch = '',
        string $itemVisibility = 'active',
        bool $showDone = false,
    ): void {
        $this->boardId = $boardId;
        $this->filterAssigneeId = $filterAssigneeId;
        $this->filterUnassigned = $filterUnassigned;
        $this->filterType = $filterType;
        $this->filterGroupIds = $filterGroupIds;
        $this->filterSearch = $filterSearch;
        $this->itemVisibility = in_array($itemVisibility, ['active', 'archived'], true) ? $itemVisibility : 'active';
        $this->showDone = $showDone;
    }

    public function archiveItem(int $itemId): void
    {
        $item = Item::query()->where('board_id', $this->boardId)->find($itemId);
        if (! $item) {
            return;
        }
        $item->archive();
        unset($this->board);
        session()->flash('success', 'Item archived.');
    }

    public function unarchiveItem(int $itemId): void
    {
        $item = Item::query()->where('board_id', $this->boardId)->find($itemId);
        if (! $item) {
            return;
        }
        $item->unarchive();
        unset($this->board);
        session()->flash('success', 'Item restored.');
    }

    public function getBoardProperty(): ?Board
    {
        $doneGroupIds = [];
        if ($this->itemVisibility === 'active' && ! $this->showDone) {
            $doneGroupIds = Group::query()
                ->where('board_id', $this->boardId)
                ->get()
                ->filter(fn (Group $g) => $g->isDone())
                ->pluck('id')
                ->all();
        }

        $groupsQuery = fn ($q) => $q->orderBy('position')->when(! empty($this->filterGroupIds), fn ($sub) => $sub->whereIn('id', $this->filterGroupIds))->with(['items' => function ($q) use ($doneGroupIds) {
            $q->orderBy('position')->limit(150)->with('assignee')->withCount('children');
            if ($this->itemVisibility === 'archived') {
                $q->archived();
            } else {
                $q->active();
            }
            // Hide Done items while keeping the column for drop-to-complete.
            if ($doneGroupIds !== []) {
                $q->where(function ($sub) use ($doneGroupIds) {
                    $sub->whereNull('group_id')->orWhereNotIn('group_id', $doneGroupIds);
                });
            }
            if ($this->filterUnassigned) {
                $q->whereNull('assignee_id');
            } elseif ($this->filterAssigneeId !== null) {
                $q->where('assignee_id', $this->filterAssigneeId);
            }
            if ($this->filterType !== null) {
                $q->where('item_type', $this->filterType);
            }
            if ($this->filterSearch !== '') {
                $search = '%' . strtolower($this->filterSearch) . '%';
                $numSearch = '%' . $this->filterSearch . '%';
                $castType = \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite' ? 'TEXT' : 'CHAR';
                $q->where(function ($sub) use ($search, $numSearch, $castType) {
                    $sub->whereRaw('LOWER(name) LIKE ?', [$search])
                        ->orWhereRaw("CAST(number AS {$castType}) LIKE ?", [$numSearch]);
                });
            }
        }]);

        $board = Board::with(['groups' => $groupsQuery])
            ->where('id', $this->boardId)
            ->first();

        return $board;
    }
};
?>

@php
    $board = $this->board;
    $isArchivedView = $itemVisibility === 'archived';
@endphp
@if($board)
<div
    class="js-kanban-board flex h-full min-h-[320px] w-full min-w-0 max-w-full flex-1 flex-col overflow-x-auto overflow-y-hidden rounded-lg border border-gray-700 border-b-2 border-b-slate-400/70 bg-gray-900 pb-1 [scrollbar-gutter:stable]"
    data-board-id="{{ $board->id }}"
    data-hide-done="{{ ($itemVisibility === 'active' && ! $showDone) ? '1' : '0' }}"
>
    <div class="flex min-h-0 min-w-full flex-1">
        @foreach($board->groups as $index => $group)
            @php $groupIsDone = $group->isDone(); @endphp
            <div
                class="kanban-column flex min-h-0 min-w-[150px] flex-1 flex-col border-r border-gray-700 last:border-r-0"
                data-group-id="{{ $group->id }}"
                data-is-done="{{ $groupIsDone ? '1' : '0' }}"
            >
                <div class="shrink-0 border-b border-gray-700 bg-gray-800 px-3 py-2">
                    <h3 class="truncate text-sm font-medium text-white">
                        {{ $group->name }}
                        <span class="text-gray-400">({{ $group->items->count() }})</span>
                        @if($group->wip_limit)
                            <span class="{{ $group->items->count() > $group->wip_limit ? 'text-red-400' : 'text-gray-400' }}">/ {{ $group->wip_limit }}</span>
                        @endif
                        @if($groupIsDone && $itemVisibility === 'active' && ! $showDone)
                            <span class="ml-1 text-[10px] font-normal text-gray-500">hidden</span>
                        @endif
                    </h3>
                </div>
                <div class="kanban-column-body flex min-h-0 flex-1 flex-col gap-1.5 overflow-y-auto p-2 bg-gray-800">
                    @unless($isArchivedView)
                    <form action="{{ route('items.store', $board) }}" method="POST" class="shrink-0 rounded-md border border-dashed border-gray-600 p-1.5" x-data="{ title: '' }">
                        @csrf
                        <input type="hidden" name="group_id" value="{{ $group->id }}" />
                        <input type="hidden" name="view" value="kanban" />
                        <div class="flex items-center gap-1">
                            <input type="text" name="name" placeholder="+ Add item" required class="min-w-0 flex-1 rounded border-0 bg-transparent text-[13px] text-gray-200 placeholder-gray-500 focus:ring-0" x-model="title" />
                            <button type="submit" x-show="title.trim().length > 0" x-cloak x-transition class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg bg-emerald-600 text-white hover:bg-emerald-500" title="Create task">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            </button>
                        </div>
                    </form>
                    @endunless
                    @foreach($group->items as $item)
                        <div
                            class="kanban-item group cursor-grab rounded-md border border-gray-600/80 bg-gray-700/90 px-2 py-1.5 transition-opacity duration-200 active:cursor-grabbing hover:border-gray-500"
                            draggable="{{ $isArchivedView ? 'false' : 'true' }}"
                            data-item-id="{{ $item->id }}"
                        >
                            <div class="flex items-center justify-between gap-1">
                                <button type="button" class="js-kanban-open-item flex min-w-0 flex-1 items-center gap-1 truncate text-left text-[13px] leading-snug text-gray-200 hover:text-white hover:underline focus:outline-none" data-item-id="{{ $item->id }}" data-href="{{ route('boards.show.item', ['board' => $board->id, 'item' => $item->number, 'view' => 'kanban']) }}" title="{{ $item->name }}" aria-label="Open item #{{ $item->number }}"><span class="shrink-0 text-gray-400">#{{ $item->number }}</span>@if($item->parent_id)<span class="shrink-0 text-gray-400" title="Has parent">↳</span>@endif<span class="min-w-0 flex-1 truncate">{{ $item->name }}</span></button>
                                <button
                                    type="button"
                                    wire:click="{{ $isArchivedView ? 'unarchiveItem' : 'archiveItem' }}({{ $item->id }})"
                                    wire:confirm="{{ $isArchivedView ? 'Restore this item to the active board?' : 'Archive this item?' }}"
                                    class="flex-shrink-0 rounded px-1 py-0.5 text-[10px] font-medium text-gray-400 opacity-0 transition hover:text-amber-300 group-hover:opacity-100"
                                    title="{{ $isArchivedView ? 'Restore' : 'Archive' }}"
                                    aria-label="{{ $isArchivedView ? 'Restore' : 'Archive' }} item #{{ $item->number }}"
                                >{{ $isArchivedView ? '↩' : 'Archive' }}</button>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[11px]">
                                @if($item->item_type === 'bug')
                                    <span class="text-red-400">Bug</span>
                                @else
                                    <span class="text-amber-400">Task</span>
                                @endif
                                @if($item->dev_tag)
                                    @php $devTagLabel = \App\Models\Item::devTagLabel($item->dev_tag); @endphp
                                    @if($devTagLabel)
                                        <span class="rounded bg-violet-500/25 px-1 py-px text-violet-200">{{ $devTagLabel }}</span>
                                    @endif
                                @endif
                                @if(($item->children_count ?? 0) > 0)
                                    <span class="text-gray-400" title="Sub-items">{{ $item->children_count }} sub</span>
                                @endif
                                @if(($item->priority ?? 'medium') === 'critical' || ($item->priority ?? 'medium') === 'high')
                                    <span class="rounded px-1 {{ ($item->priority ?? '') === 'critical' ? 'bg-red-500/30 text-red-300' : 'bg-amber-500/30 text-amber-300' }}">{{ ucfirst($item->priority ?? '') }}</span>
                                @endif
                                @if($item->due_at)
                                    <span class="{{ $item->isOverdue() ? 'text-red-400' : 'text-gray-400' }}" title="Due {{ $item->due_at->format('M j, Y') }}">{{ $item->due_at->format('M j') }}</span>
                                @endif
                                @if($item->assignee)
                                    @php
                                        $assignee = $item->assignee;
                                        $photoUrl = route('api.users.photo', $assignee);
                                        $nameParts = explode(' ', trim($assignee->name));
                                        $initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr($nameParts[count($nameParts) - 1], 0, 1) : ''));
                                    @endphp
                                    <div class="ml-auto flex min-w-0 items-center gap-1" title="{{ $assignee->name }}">
                                        <div class="relative h-4 w-4 shrink-0 overflow-hidden rounded-full bg-gray-500">
                                            <img src="{{ $photoUrl }}" alt="{{ $assignee->name }}" draggable="false" class="h-full w-full object-cover select-none" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
                                            <div class="hidden h-full w-full items-center justify-center bg-gray-600 text-[10px] font-medium text-white">
                                                {{ $initials }}
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    <span class="ml-auto text-gray-500">Unassigned</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>
@else
<div class="rounded-lg border border-red-200 bg-red-50 p-4 text-red-700">Board not found.</div>
@endif

@if($board)
<script>
(function() {
    var draggedCard = null;

    function initKanbanDragDrop() {
        if (document.body.hasAttribute('data-kanban-dnd')) return;
        document.body.setAttribute('data-kanban-dnd', '1');
        document.addEventListener('dragstart', function(e) {
            var card = e.target.closest && e.target.closest('.kanban-item');
            if (!card || card.getAttribute('draggable') !== 'true') return;
            if (e.target.tagName === 'IMG') return;
            draggedCard = card;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', card.getAttribute('data-item-id') || '');
            e.dataTransfer.setData('application/x-item-id', card.getAttribute('data-item-id') || '');
        }, true);

        document.addEventListener('dragend', function() { draggedCard = null; }, true);

        document.addEventListener('dragenter', function(e) {
            if (e.target.closest('.kanban-column')) e.preventDefault();
        }, true);

        document.addEventListener('dragover', function(e) {
            var column = e.target.closest('.kanban-column');
            if (column) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
            }
        }, true);

        document.addEventListener('drop', function(e) {
            var column = e.target.closest('.kanban-column');
            if (!column) return;
            e.preventDefault();
            e.stopPropagation();
            var boardEl = column.closest('.js-kanban-board');
            var boardId = boardEl && boardEl.getAttribute('data-board-id');
            var groupId = column.getAttribute('data-group-id');
            var itemId = e.dataTransfer.getData('text/plain') || e.dataTransfer.getData('application/x-item-id');
            if (!itemId || !groupId || !boardId) return;

            var card = draggedCard;
            draggedCard = null;
            var columnBody = column.querySelector('.kanban-column-body');
            if (!columnBody || !card) return;

            var oldParent = card.parentNode;
            var oldNext = card.nextSibling;

            columnBody.appendChild(card);

            var url = '/boards/' + boardId + '/items/' + itemId + '/move';
            var token = document.querySelector('meta[name="csrf-token"]') && document.querySelector('meta[name="csrf-token"]').content;
            var formData = new FormData();
            formData.append('group_id', groupId);
            formData.append('_token', token || '');
            var headers = { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };
            if (token) headers['X-CSRF-TOKEN'] = token;
            fetch(url, { method: 'POST', body: formData, headers: headers })
                .then(function(r) {
                    if (!r.ok && card && oldParent) {
                        if (oldNext) oldParent.insertBefore(card, oldNext);
                        else oldParent.appendChild(card);
                        return;
                    }
                    // Drop onto Done while Hide Done is on → clear the card from the board.
                    var boardEl = column.closest('.js-kanban-board');
                    var hideDone = boardEl && boardEl.getAttribute('data-hide-done') === '1';
                    var targetDone = column.getAttribute('data-is-done') === '1';
                    if (r.ok && hideDone && targetDone && card) {
                        card.style.opacity = '0';
                        setTimeout(function() { card.remove(); }, 180);
                    }
                });
        }, true);

        document.addEventListener('click', function(e) {
            var openLink = e.target.closest('.js-kanban-open-item');
            if (openLink) {
                e.preventDefault();
                e.stopPropagation();
                var itemId = openLink.getAttribute('data-item-id');
                if (itemId && window.dispatchEvent) {
                    window.dispatchEvent(new CustomEvent('open-item', { detail: { itemId: parseInt(itemId, 10) } }));
                }
                var href = openLink.getAttribute('data-href') || (openLink.href ? openLink.href : '');
                if (history.replaceState && href) history.replaceState({}, '', href);
                if (openLink.blur) openLink.blur();
                return;
            }
        }, true);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initKanbanDragDrop);
    } else {
        initKanbanDragDrop();
    }
})();
</script>
@endif

<style>
.js-kanban-board {
    scrollbar-width: thin;
    scrollbar-color: rgb(107 114 128) rgb(17 24 39);
}

.js-kanban-board::-webkit-scrollbar {
    height: 10px;
}

.js-kanban-board::-webkit-scrollbar-track {
    background: rgb(17 24 39);
}

.js-kanban-board::-webkit-scrollbar-thumb {
    background: rgb(75 85 99);
    border-radius: 9999px;
}

.js-kanban-board::-webkit-scrollbar-thumb:hover {
    background: rgb(107 114 128);
}
</style>
