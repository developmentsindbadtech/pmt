<?php

use App\Models\Board;
use Livewire\Component;

new class extends Component
{
    public int $boardId;

    public ?int $filterAssigneeId = null;

    public bool $filterUnassigned = false;

    public ?string $filterType = null;

    public function mount(int $boardId, ?int $filterAssigneeId = null, bool $filterUnassigned = false, ?string $filterType = null): void
    {
        $this->boardId = $boardId;
        $this->filterAssigneeId = $filterAssigneeId;
        $this->filterUnassigned = $filterUnassigned;
        $this->filterType = $filterType;
    }

    public function getBoardProperty(): ?Board
    {
        $board = Board::with(['groups' => fn ($q) => $q->orderBy('position')->with(['items' => function ($q) {
            $q->orderBy('position')->limit(150)->with('assignee');
            if ($this->filterUnassigned) {
                $q->whereNull('assignee_id');
            } elseif ($this->filterAssigneeId !== null) {
                $q->where('assignee_id', $this->filterAssigneeId);
            }
            if ($this->filterType !== null) {
                $q->where('item_type', $this->filterType);
            }
        }])])
            ->where('id', $this->boardId)
            ->first();

        return $board;
    }
};
?>

@php
    $board = $this->board;
@endphp
@if($board)
<div class="js-kanban-board flex h-[calc(100vh-16rem)] max-h-[calc(100vh-16rem)] min-h-[420px] flex-col overflow-x-auto overflow-y-hidden rounded-lg border border-gray-700 bg-gray-900" data-board-id="{{ $board->id }}">
    <div class="flex min-h-0 flex-1">
        @foreach($board->groups as $index => $group)
            <div
                class="kanban-column flex min-h-0 min-w-[200px] flex-1 flex-col border-r border-gray-700 last:border-r-0"
                data-group-id="{{ $group->id }}"
            >
                <div class="shrink-0 border-b border-gray-700 bg-gray-800 px-4 py-3">
                    <h3 class="font-medium text-white">{{ $group->name }}</h3>
                </div>
                <div class="kanban-column-body flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto p-3 bg-gray-800">
                    @if($index === 0)
                    <form action="{{ route('items.store', $board) }}" method="POST" class="shrink-0 rounded-md border border-dashed border-gray-600 p-2" x-data="{ title: '' }">
                        @csrf
                        <input type="hidden" name="group_id" value="{{ $group->id }}" />
                        <input type="hidden" name="view" value="kanban" />
                        <div class="flex items-center gap-1">
                            <input type="text" name="name" placeholder="+ Add item" required class="min-w-0 flex-1 rounded border-0 bg-transparent text-sm text-gray-200 placeholder-gray-500 focus:ring-0" x-model="title" />
                            <button type="submit" x-show="title.trim().length > 0" x-cloak x-transition class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-emerald-600 text-white hover:bg-emerald-500" title="Create task">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            </button>
                        </div>
                    </form>
                    @endif
                    @foreach($group->items as $item)
                        <div
                            class="kanban-item cursor-grab rounded border border-gray-600/80 bg-gray-700/90 p-2.5 transition-opacity duration-200 active:cursor-grabbing hover:border-gray-500"
                            draggable="true"
                            data-item-id="{{ $item->id }}"
                            data-delete-url="{{ route('items.destroy', [$board, $item]) }}"
                        >
                            <div class="flex items-start justify-between gap-1.5">
                                <a href="{{ route('boards.show.item', ['board' => $board->id, 'item' => $item->number, 'view' => 'kanban']) }}" class="js-kanban-open-item flex min-w-0 flex-1 items-baseline gap-1 text-sm text-gray-200 hover:text-white hover:underline focus:outline-none focus:bg-transparent" data-item-id="{{ $item->id }}" title="{{ $item->name }}" onclick="event.stopPropagation()" draggable="false"><span class="shrink-0 text-gray-500">#{{ $item->number }}</span><span class="min-w-0 flex-1 truncate">{{ $item->name }}</span></a>
                                <button type="button" class="js-kanban-delete flex-shrink-0 rounded p-0.5 text-gray-500 hover:text-red-400" title="Delete" aria-label="Delete" draggable="false">&#215;</button>
                            </div>
                            <div class="mt-1 flex items-center gap-2 text-xs">
                                @if($item->item_type === 'bug')
                                    <span class="text-red-400">Bug</span>
                                @else
                                    <span class="text-amber-400">Task</span>
                                @endif
                                <span class="text-gray-600">·</span>
                                @if($item->assignee)
                                    @php
                                        $assignee = $item->assignee;
                                        $photoUrl = route('api.users.photo', $assignee);
                                        $nameParts = explode(' ', trim($assignee->name));
                                        $initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr($nameParts[count($nameParts) - 1], 0, 1) : ''));
                                    @endphp
                                    <div class="flex items-center gap-1.5">
                                        <div class="relative h-4 w-4 shrink-0 overflow-hidden rounded-full bg-gray-500">
                                            <img src="{{ $photoUrl }}" alt="{{ $assignee->name }}" class="h-full w-full object-cover" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
                                            <div class="hidden h-full w-full items-center justify-center bg-gray-600 text-[10px] font-medium text-white">
                                                {{ $initials }}
                                            </div>
                                        </div>
                                        <span class="text-gray-400">{{ $assignee->name }}</span>
                                    </div>
                                @else
                                    <span class="text-gray-400">Unassigned</span>
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
            var card = e.target.closest('.kanban-item');
            if (!card || !card.draggable) return;
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
                    }
                });
        }, true);

        document.addEventListener('click', function(e) {
            var openLink = e.target.closest('a.js-kanban-open-item');
            if (openLink) {
                e.preventDefault();
                e.stopPropagation();
                var itemId = openLink.getAttribute('data-item-id');
                if (itemId && window.dispatchEvent) {
                    window.dispatchEvent(new CustomEvent('open-item', { detail: { itemId: parseInt(itemId, 10) } }));
                }
                if (history.replaceState && openLink.href) history.replaceState({}, '', openLink.href);
                if (openLink.blur) openLink.blur();
                return;
            }
            var btn = e.target.closest('.js-kanban-delete');
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            var card = btn.closest('.kanban-item');
            if (!card) return;
            var url = card.getAttribute('data-delete-url');
            if (!url || !confirm('Delete this task?')) return;
            var token = document.querySelector('meta[name="csrf-token"]') && document.querySelector('meta[name="csrf-token"]').content;
            fetch(url, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': token || '', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function(r) {
                if (r.ok) {
                    card.style.opacity = '0';
                    setTimeout(function() { card.remove(); }, 200);
                } else {
                    window.location.reload();
                }
            });
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
