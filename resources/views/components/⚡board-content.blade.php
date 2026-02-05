<?php

use App\Models\Board;
use App\Models\Item;
use App\Models\User;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public int $boardId;

    public string $view = 'kanban';

    public ?int $selectedItemId = null;

    /** @var int|null Filter by assignee (null = All or Unassigned) */
    public ?int $filterAssigneeId = null;

    /** @var bool When true, filter to items with no assignee */
    public bool $filterUnassigned = false;

    /** @var string|null Filter by type: 'task'|'bug'|null = All */
    public ?string $filterType = null;

    public string $activeTab = 'details';

    public function mount(int $boardId, string $view = 'kanban', ?int $filterAssigneeId = null, bool $filterUnassigned = false, ?string $filterType = null, ?int $selectedItemId = null): void
    {
        $this->boardId = $boardId;
        $this->view = $view;
        $this->filterAssigneeId = $filterAssigneeId;
        $this->filterUnassigned = $filterUnassigned;
        $this->filterType = $filterType;
        // Use provided selectedItemId or check query parameter (backward compatibility)
        $this->selectedItemId = $selectedItemId ?? (request()->has('item') ? (int) request('item') : null);
    }
    
    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function openItem($itemId = null): void
    {
        $this->selectedItemId = $itemId !== null && $itemId !== '' ? (int) $itemId : null;
    }

    #[On('open-item')]
    public function openItemFromEvent($payload = null): void
    {
        $itemId = is_array($payload) ? ($payload['itemId'] ?? null) : $payload;
        $this->openItem($itemId);
    }

    public function closePanel(): void
    {
        $this->selectedItemId = null;
    }

    public function getBoardProperty(): ?Board
    {
        return Board::with(['columns' => fn ($q) => $q->orderBy('position'), 'groups', 'users'])
            ->where('id', $this->boardId)
            ->first();
    }

    public function getItemProperty(): ?Item
    {
        if (! $this->selectedItemId) {
            return null;
        }
        
        // Always load creator to avoid N+1 queries, but it's lightweight
        return Item::with(['assignee', 'group', 'comments' => fn ($q) => $q->with('user')->orderByDesc('created_at')->limit(50), 'creator', 'activities' => fn ($q) => $q->with('user')->orderByDesc('created_at')])
            ->where('board_id', $this->boardId)
            ->find($this->selectedItemId);
    }

    public function getUsersProperty()
    {
        $board = $this->board;
        if (! $board) {
            return collect();
        }
        
        // Get users assigned to this board OR admins (who can see all boards)
        return User::query()
            ->where(function ($query) use ($board) {
                // Users assigned to this board
                $query->whereHas('boards', function ($q) use ($board) {
                    $q->where('boards.id', $board->id);
                })
                // OR admins (who have access to all boards)
                ->orWhere('is_admin', true);
            })
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'is_admin']);
    }
};
?>

@php
    $board = $this->board;
    $item = $this->selectedItemId ? $this->item : null;
    $users = $board ? $this->users : collect();
@endphp
<div x-data x-on:open-item.window="$wire.openItem($event.detail.itemId)">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <a href="{{ route('boards.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Boards</a>
            <h1 class="mt-1 text-2xl font-semibold text-gray-900">{{ $board?->name }}</h1>
            @if ($board?->description)
                <p class="mt-1 text-sm text-gray-500">{{ $board->description }}</p>
            @endif
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            @if($board)
            @php
                $assigneeSelectValue = $filterUnassigned ? 'unassigned' : ($filterAssigneeId ?? '');
            @endphp
            <div class="flex items-center gap-2" x-data="{ assigneeSelect: '{{ $assigneeSelectValue }}', typeSelect: '{{ $filterType ?? '' }}', appliedAssignee: '{{ $assigneeSelectValue }}', appliedType: '{{ $filterType ?? '' }}' }">
                <form action="{{ route('boards.filters.apply', $board) }}" method="POST" class="flex items-center gap-2">
                    @csrf
                    <input type="hidden" name="view" value="{{ $view }}" />
                    <label class="text-sm text-gray-600">Assignee</label>
                    <select name="assignee" class="rounded-md border border-gray-300 text-sm text-gray-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500" x-model="assigneeSelect">
                        <option value="">All</option>
                        <option value="unassigned">Unassigned</option>
                        @foreach($users as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                    <label class="text-sm text-gray-600">Type</label>
                    <select name="type" class="rounded-md border border-gray-300 text-sm text-gray-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500" x-model="typeSelect">
                        <option value="">All</option>
                        <option value="task">Task</option>
                        <option value="bug">Bug</option>
                    </select>
                    <button type="submit" x-show="assigneeSelect !== appliedAssignee || typeSelect !== appliedType" x-cloak class="rounded-md bg-blue-600 px-2.5 py-1.5 text-sm text-white hover:bg-blue-700">Apply</button>
                </form>
            </div>
            @endif
            @php
                $assigneeParam = $filterUnassigned ? 'unassigned' : $filterAssigneeId;
            @endphp
            <a href="{{ route('boards.show', array_filter(['board' => $board, 'view' => 'kanban', 'assignee' => $assigneeParam, 'type' => $filterType])) }}" class="rounded-md px-3 py-1.5 text-sm {{ $view === 'kanban' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">Kanban View</a>
            <a href="{{ route('boards.show', array_filter(['board' => $board, 'view' => 'table', 'assignee' => $assigneeParam, 'type' => $filterType])) }}" class="rounded-md px-3 py-1.5 text-sm {{ $view === 'table' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">List View</a>
            <a href="{{ route('boards.export-csv', $board) }}" class="rounded-md bg-green-600 px-3 py-1.5 text-sm text-white hover:bg-green-700">Download CSV</a>
            @if(auth()->user()->is_admin)
            <form action="{{ route('boards.destroy', $board) }}" method="POST" class="inline" onsubmit="return confirm('Delete this board and all its tasks?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="rounded-md px-3 py-1.5 text-sm text-red-600 hover:bg-red-50 hover:text-red-700">Delete board</button>
            </form>
            @endif
        </div>
    </div>

    @if (session('success'))
        <div
            x-data="{ show: true }"
            x-show="show"
            x-init="setTimeout(() => show = false, 5000)"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="fixed left-1/2 top-5 z-[100] flex max-w-sm -translate-x-1/2 items-start gap-2 rounded-xl border border-green-200/80 bg-white px-4 py-3 text-sm text-green-800 shadow-lg ring-1 ring-black/5"
            role="alert"
            aria-live="polite"
        >
            <span class="flex-1">{{ session('success') }}</span>
            <button type="button" @click="show = false" class="shrink-0 rounded p-1 text-green-600 hover:bg-green-100 hover:text-green-800 focus:outline-none focus:ring-2 focus:ring-green-500/50" aria-label="Dismiss">&times;</button>
        </div>
    @endif

    @if($view === 'table')
        @livewire('table-view', ['boardId' => $boardId, 'filterAssigneeId' => $filterAssigneeId, 'filterUnassigned' => $filterUnassigned, 'filterType' => $filterType])
    @else
        <div class="rounded-lg bg-gray-900" wire:ignore>
            @livewire('kanban-view', ['boardId' => $boardId, 'filterAssigneeId' => $filterAssigneeId, 'filterUnassigned' => $filterUnassigned, 'filterType' => $filterType])
        </div>
    @endif

    @if($selectedItemId)
        {{-- Backdrop: dims and freezes the board; click to close --}}
        <div
            class="fixed inset-0 z-40 bg-black/40 cursor-default"
            role="button"
            tabindex="0"
            aria-label="Close panel"
            data-board-url="{{ route('boards.show', ['board' => $board, 'view' => $view]) }}"
            onclick="var u = this.getAttribute('data-board-url'); if (u && history.replaceState) history.replaceState({}, '', u)"
            wire:click="closePanel"
            wire:key="detail-backdrop"
        ></div>
        {{-- Right-side panel: minimalist, compact, professional --}}
        <div class="item-detail-panel fixed inset-y-0 right-0 z-50 w-full max-w-md border-l border-gray-200 bg-gray-50/95 shadow-xl backdrop-blur-sm" role="dialog" aria-label="Item details">
            <div class="flex h-full flex-col bg-white">
                <div class="flex shrink-0 items-center justify-between border-b border-gray-100 px-4 py-2.5">
                    <h2 class="text-sm font-semibold tracking-tight text-gray-800">
                        {{ $item ? ($item->isBug() ? 'Bug' : 'Task') . ' #' . $item->number : '' }}
                    </h2>
                    <button type="button" wire:click="closePanel" data-board-url="{{ route('boards.show', ['board' => $board, 'view' => $view]) }}" onclick="var u = this.getAttribute('data-board-url'); if (u && history.replaceState) history.replaceState({}, '', u)" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-gray-300 bg-gray-50 text-base font-medium text-gray-600 hover:border-gray-400 hover:bg-gray-100 hover:text-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-1 transition-colors" title="Close" aria-label="Close">&times;</button>
                </div>
                @if($item && $board)
                <div class="flex flex-col flex-1 min-h-0" x-data="{ 
                    init() {
                        this.$watch('$wire.activeTab', () => {
                            this.$refs.scrollContainer.scrollTop = 0;
                        });
                    }
                }">
                <!-- Tabs -->
                <div class="shrink-0 border-b border-gray-200">
                    <nav class="flex -mb-px px-4" aria-label="Tabs">
                        <button 
                            @click="$wire.setTab('details')"
                            :class="($wire.activeTab === 'details') ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
                            class="whitespace-nowrap border-b-2 px-3 py-2 text-xs font-medium transition-colors"
                        >
                            Details
                        </button>
                        <button 
                            @click="$wire.setTab('history')"
                            :class="($wire.activeTab === 'history') ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
                            class="whitespace-nowrap border-b-2 px-3 py-2 text-xs font-medium transition-colors"
                        >
                            History
                        </button>
                    </nav>
                </div>
                <div class="flex-1 overflow-y-auto overflow-x-hidden px-4 py-3" 
                     x-ref="scrollContainer"
                     style="scrollbar-width: thin; scrollbar-color: rgb(156 163 175) rgb(243 244 246);">
                    <div x-show="$wire.activeTab === 'details'" style="display: {{ $activeTab === 'details' ? 'block' : 'none' }};">
                    <form action="{{ route('items.update', [$board, $item]) }}" method="POST" class="space-y-3">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="view" value="{{ $view }}" />
                        <input type="hidden" name="return_item" value="1" />
                        <div x-data="{ editing: false }" class="rounded border border-gray-200 px-3 py-2">
                            <div class="flex items-center justify-between">
                                <label class="block text-xs font-medium uppercase tracking-wide text-gray-500">Title</label>
                                <button type="button" @click="editing = !editing" class="text-xs text-blue-600 hover:text-blue-700 hover:underline" x-text="editing ? 'Cancel' : 'Edit'"></button>
                            </div>
                            <div x-show="!editing" class="mt-0.5 text-sm text-gray-900">
                                {{ $item->name }}
                            </div>
                            <input x-show="editing" x-cloak type="text" name="name" :disabled="!editing" value="{{ old('name', $item->name) }}" class="mt-0.5 w-full rounded border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-900 placeholder-gray-400 focus:border-gray-400 focus:ring-1 focus:ring-gray-400" placeholder="Task title" />
                            <input x-show="!editing" type="hidden" name="name" :disabled="editing" value="{{ $item->name }}" />
                            @error('name') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        @if($board->groups->isNotEmpty())
                        <div class="grid grid-cols-2 gap-3">
                            <div x-data="{ editing: false }" class="rounded border border-gray-200 px-3 py-2">
                                <div class="flex items-center justify-between">
                                    <label class="block text-xs font-medium uppercase tracking-wide text-gray-500">Type</label>
                                    <button type="button" @click="editing = !editing" class="text-xs text-blue-600 hover:text-blue-700 hover:underline" x-text="editing ? 'Cancel' : 'Edit'"></button>
                                </div>
                                <div x-show="!editing" class="mt-0.5 text-sm text-gray-900">
                                    {{ $item->item_type === 'bug' ? 'Bug' : 'Task' }}
                                </div>
                                <select x-show="editing" x-cloak name="item_type" :disabled="!editing" class="mt-0.5 w-full rounded border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-900 focus:border-gray-400 focus:ring-1 focus:ring-gray-400">
                                    <option value="task" {{ (old('item_type', $item->item_type) === 'task') ? 'selected' : '' }}>Task</option>
                                    <option value="bug" {{ (old('item_type', $item->item_type) === 'bug') ? 'selected' : '' }}>Bug</option>
                                </select>
                                <input x-show="!editing" type="hidden" name="item_type" :disabled="editing" value="{{ $item->item_type }}" />
                            </div>
                            <div x-data="{ editing: false }" class="rounded border border-gray-200 px-3 py-2">
                                <div class="flex items-center justify-between">
                                    <label class="block text-xs font-medium uppercase tracking-wide text-gray-500">Status</label>
                                    <button type="button" @click="editing = !editing" class="text-xs text-blue-600 hover:text-blue-700 hover:underline" x-text="editing ? 'Cancel' : 'Edit'"></button>
                                </div>
                                <div x-show="!editing" class="mt-0.5 text-sm text-gray-900">
                                    {{ $item->group?->name ?? '—' }}
                                </div>
                                <select x-show="editing" x-cloak name="group_id" :disabled="!editing" class="mt-0.5 w-full rounded border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-900 focus:border-gray-400 focus:ring-1 focus:ring-gray-400">
                                    @foreach($board->groups as $g)
                                        <option value="{{ $g->id }}" {{ (old('group_id', $item->group_id) == $g->id) ? 'selected' : '' }}>{{ $g->name }}</option>
                                    @endforeach
                                </select>
                                <input x-show="!editing" type="hidden" name="group_id" :disabled="editing" value="{{ $item->group_id ?? '' }}" />
                            </div>
                        </div>
                        @else
                        <div x-data="{ editing: false }" class="rounded border border-gray-200 px-3 py-2">
                            <div class="flex items-center justify-between">
                                <label class="block text-xs font-medium uppercase tracking-wide text-gray-500">Type</label>
                                <button type="button" @click="editing = !editing" class="text-xs text-blue-600 hover:text-blue-700 hover:underline" x-text="editing ? 'Cancel' : 'Edit'"></button>
                            </div>
                            <div x-show="!editing" class="mt-0.5 text-sm text-gray-900">
                                {{ $item->item_type === 'bug' ? 'Bug' : 'Task' }}
                            </div>
                            <select x-show="editing" x-cloak name="item_type" :disabled="!editing" class="mt-0.5 w-full rounded border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-900 focus:border-gray-400 focus:ring-1 focus:ring-gray-400">
                                <option value="task" {{ (old('item_type', $item->item_type) === 'task') ? 'selected' : '' }}>Task</option>
                                <option value="bug" {{ (old('item_type', $item->item_type) === 'bug') ? 'selected' : '' }}>Bug</option>
                            </select>
                            <input x-show="!editing" type="hidden" name="item_type" :disabled="editing" value="{{ $item->item_type }}" />
                        </div>
                        @endif
                        <div x-data="{ editing: false }" class="rounded border border-gray-200 px-3 py-2">
                            <div class="flex items-center justify-between">
                                <label class="block text-xs font-medium uppercase tracking-wide text-gray-500">Assignee</label>
                                <button type="button" @click="editing = !editing" class="text-xs text-blue-600 hover:text-blue-700 hover:underline" x-text="editing ? 'Cancel' : 'Edit'"></button>
                            </div>
                            <div x-show="!editing" class="mt-0.5">
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
                                        <span class="text-sm text-gray-900">{{ $assignee->name }}</span>
                                    </div>
                                @else
                                    <span class="text-sm text-gray-900">— None —</span>
                                @endif
                            </div>
                            <select x-show="editing" x-cloak name="assignee_id" :disabled="!editing" class="mt-0.5 w-full rounded border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-900 focus:border-gray-400 focus:ring-1 focus:ring-gray-400">
                                <option value="">— None —</option>
                                @foreach($users as $u)
                                    <option value="{{ $u->id }}" {{ (old('assignee_id', $item->assignee_id) == $u->id) ? 'selected' : '' }}>{{ $u->name }}</option>
                                @endforeach
                            </select>
                            <input x-show="!editing" type="hidden" name="assignee_id" :disabled="editing" value="{{ $item->assignee_id ?? '' }}" />
                        </div>
                        @if($item->isTask())
                        <div x-data="{ editing: false }" class="rounded border border-gray-200 px-3 py-2">
                            <div class="flex items-center justify-between">
                                <label class="block text-xs font-medium uppercase tracking-wide text-gray-500">Description</label>
                                <button type="button" @click="editing = !editing" class="text-xs text-blue-600 hover:text-blue-700 hover:underline" x-text="editing ? 'Cancel' : 'Edit'"></button>
                            </div>
                            <div x-show="!editing" class="mt-0.5 min-h-[7rem] w-full rounded border border-transparent px-2.5 py-1.5 text-sm text-gray-900 whitespace-pre-wrap">
                                @if($item->description)
                                    <div class="text-left w-full">{!! preg_replace('/@(\w+)/', '<span class="text-blue-600 font-medium">@$1</span>', e($item->description)) !!}</div>
                                @else
                                    <div class="text-left"><span class="text-gray-400 italic">No description</span></div>
                                @endif
                            </div>
                            <textarea x-show="editing" x-cloak name="description" rows="4" data-mention-board-id="{{ $board->id }}" class="js-mention-textarea mt-0.5 w-full min-h-[7rem] max-h-[14rem] resize-y overflow-y-auto rounded border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-900 placeholder-gray-400 focus:border-gray-400 focus:ring-1 focus:ring-gray-400" placeholder="Optional (use @ to mention someone)">{{ old('description', $item->description) }}</textarea>
                        </div>
                        @else
                        <div x-data="{ editing: false }" class="rounded border border-gray-200 px-3 py-2">
                            <div class="flex items-center justify-between">
                                <label class="block text-xs font-medium uppercase tracking-wide text-gray-500">Repro steps</label>
                                <button type="button" @click="editing = !editing" class="text-xs text-blue-600 hover:text-blue-700 hover:underline" x-text="editing ? 'Cancel' : 'Edit'"></button>
                            </div>
                            <div x-show="!editing" class="mt-0.5 min-h-[7rem] w-full rounded border border-transparent px-2.5 py-1.5 text-sm text-gray-900 whitespace-pre-wrap">
                                @if($item->repro_steps)
                                    <div class="text-left w-full">{!! preg_replace('/@(\w+)/', '<span class="text-blue-600 font-medium">@$1</span>', e($item->repro_steps)) !!}</div>
                                @else
                                    <div class="text-left"><span class="text-gray-400 italic">No repro steps</span></div>
                                @endif
                            </div>
                            <textarea x-show="editing" x-cloak name="repro_steps" rows="4" data-mention-board-id="{{ $board->id }}" class="js-mention-textarea mt-0.5 w-full min-h-[7rem] max-h-[14rem] resize-y overflow-y-auto rounded border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-900 placeholder-gray-400 focus:border-gray-400 focus:ring-1 focus:ring-gray-400" placeholder="Optional (use @ to mention someone)">{{ old('repro_steps', $item->repro_steps) }}</textarea>
                        </div>
                        @endif
                        <div class="pt-1">
                            <button type="submit" class="w-full rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 transition-colors">Save changes</button>
                        </div>
                    </form>
                    <div class="mt-5 border-t border-gray-100 pt-4">
                        <h3 class="text-xs font-medium uppercase tracking-wide text-gray-500">Screenshots</h3>
                        @if($item->attachments && count($item->attachments) > 0)
                        <ul class="mt-2 space-y-2">
                            @foreach($item->attachments as $path)
                            <li class="flex items-center gap-2 rounded border border-gray-100 bg-gray-50/80 p-2">
                                <a href="{{ asset('storage/'.$path) }}" target="_blank" rel="noopener" class="shrink-0 overflow-hidden rounded border border-gray-200">
                                    <img src="{{ asset('storage/'.$path) }}" alt="Attachment" class="h-12 w-12 object-cover" loading="lazy" />
                                </a>
                                <form action="{{ route('items.attachments.destroy', [$board, $item]) }}" method="POST" class="flex-1" onsubmit="return confirm('Remove this image?');">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="view" value="{{ $view }}" />
                                    <input type="hidden" name="path" value="{{ $path }}" />
                                    <button type="submit" class="text-xs text-red-600 hover:text-red-700">Remove</button>
                                </form>
                            </li>
                            @endforeach
                        </ul>
                        @endif
                        <form
                            action="{{ route('items.attachments.store', [$board, $item]) }}"
                            method="POST"
                            enctype="multipart/form-data"
                            class="mt-2"
                            x-data="{ pasted: false }"
                            x-on:paste.window="
                                const dt = $event.clipboardData;
                                if (!dt || !dt.items) return;
                                for (let i = 0; i < dt.items.length; i++) {
                                    if (dt.items[i].type.indexOf('image') !== -1) {
                                        $event.preventDefault();
                                        const file = dt.items[i].getAsFile();
                                        if (file && $refs.pasteFileInput) {
                                            const dataTransfer = new DataTransfer();
                                            dataTransfer.items.add(file);
                                            $refs.pasteFileInput.files = dataTransfer.files;
                                            pasted = true;
                                        }
                                        break;
                                    }
                                }
                            "
                        >
                            @csrf
                            <input type="hidden" name="view" value="{{ $view }}" />
                            <input x-ref="pasteFileInput" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" class="hidden" x-on:change="if ($event.target.files && $event.target.files.length) pasted = true" />
                            <button type="button" x-on:click="$refs.pasteFileInput.click()" class="mb-2 flex w-full items-center justify-center rounded border border-dashed border-gray-300 bg-gray-50/50 py-3 text-xs text-gray-500 hover:border-gray-400 hover:bg-gray-50 hover:text-gray-600">
                                Click to choose or paste (Ctrl+V)
                            </button>
                            <p class="mb-2 flex items-center gap-1.5 rounded-md border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-sm font-medium text-emerald-800 shadow-sm" x-show="pasted" x-cloak x-transition>
                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white" aria-hidden="true">
                                    <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                </span>
                                Image ready. Click Upload below.
                            </p>
                            <button type="submit" class="w-full rounded bg-gray-800 px-3 py-1.5 text-xs font-medium text-white hover:bg-gray-700 transition-colors">Upload</button>
                        </form>
                    </div>
                    <div class="mt-5 border-t border-gray-100 pt-4">
                        <h3 class="text-xs font-medium uppercase tracking-wide text-gray-500">Comments</h3>
                        <ul class="mt-2 space-y-2">
                            @foreach($item->comments as $comment)
                                <li class="rounded border border-gray-100 bg-gray-50/80 px-2.5 py-2">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-start gap-2">
                                                @php
                                                    $commentUser = $comment->user;
                                                    $photoUrl = route('api.users.photo', $commentUser);
                                                    $nameParts = explode(' ', trim($commentUser->name));
                                                    $initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr($nameParts[count($nameParts) - 1], 0, 1) : ''));
                                                @endphp
                                                <div class="relative h-6 w-6 shrink-0 overflow-hidden rounded-full bg-gray-300 mt-0.5">
                                                    <img src="{{ $photoUrl }}" alt="{{ $commentUser->name }}" class="h-full w-full object-cover" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
                                                    <div class="hidden h-full w-full items-center justify-center bg-gray-400 text-xs font-medium text-white">
                                                        {{ $initials }}
                                                    </div>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <p class="text-sm text-gray-800">{!! preg_replace('/@(\w+)/', '<span class="text-blue-600 font-medium">@$1</span>', e($comment->body)) !!}</p>
                                                    <p class="mt-0.5 text-xs text-gray-400">{{ $commentUser->name }} · {{ $comment->created_at->format('M j, Y g:i A') }} <span class="text-gray-400">({{ $comment->created_at->diffForHumans() }})</span></p>
                                                </div>
                                            </div>
                                        </div>
                                        <form action="{{ route('items.comments.destroy', [$board, $item, $comment]) }}" method="POST" class="shrink-0" onsubmit="return confirm('Delete this comment?');">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="view" value="{{ $view }}" />
                                            <button type="submit" class="text-xs text-red-600 hover:text-red-700 hover:underline">Delete</button>
                                        </form>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                        <form action="{{ route('items.comments.store', [$board, $item]) }}" method="POST" class="mt-3">
                            @csrf
                            <input type="hidden" name="view" value="{{ $view }}" />
                            <textarea name="body" rows="2" data-mention-board-id="{{ $board->id }}" placeholder="Add a comment... (use @ to mention someone)" required class="js-mention-textarea w-full rounded border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-900 placeholder-gray-400 focus:border-gray-400 focus:ring-1 focus:ring-gray-400"></textarea>
                            @error('body') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                            <button type="submit" class="mt-1.5 rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 transition-colors">Add comment</button>
                        </form>
                    </div>
                    </div>
                    <div x-show="$wire.activeTab === 'history'" style="display: {{ $activeTab === 'history' ? 'block' : 'none' }};">
                    @php
                        // Pre-load relationships for history
                        if (!$item->relationLoaded('creator')) {
                            $item->load('creator');
                        }
                        if (!$item->relationLoaded('activities')) {
                            $item->load(['activities.user']);
                        }
                        
                        $historyItems = collect();
                        
                        // Add activities from database
                        foreach ($item->activities as $activity) {
                            $text = '';
                            $icon = '📝';
                            $iconBg = 'bg-gray-100';
                            $iconText = 'text-gray-700';
                            
                            switch ($activity->type) {
                                case 'created':
                                    $text = 'created this ' . ($item->isBug() ? 'bug' : 'task');
                                    $icon = '+';
                                    $iconBg = 'bg-blue-100';
                                    $iconText = 'text-blue-700';
                                    break;
                                case 'status_changed':
                                    $text = 'changed status from "' . ($activity->old_value ?? 'Unassigned') . '" to "' . ($activity->new_value ?? 'Unassigned') . '"';
                                    $icon = '🔄';
                                    $iconBg = 'bg-yellow-100';
                                    $iconText = 'text-yellow-700';
                                    break;
                                case 'assigned':
                                    if ($activity->old_value && $activity->old_value !== 'Unassigned') {
                                        $text = 'reassigned from "' . $activity->old_value . '" to "' . ($activity->new_value ?? 'Unassigned') . '"';
                                    } else {
                                        $text = 'assigned to "' . ($activity->new_value ?? 'Unassigned') . '"';
                                    }
                                    $icon = '👤';
                                    $iconBg = 'bg-green-100';
                                    $iconText = 'text-green-700';
                                    break;
                                case 'description_changed':
                                    $text = 'updated description';
                                    $icon = '📄';
                                    $iconBg = 'bg-indigo-100';
                                    $iconText = 'text-indigo-700';
                                    break;
                                case 'repro_steps_changed':
                                    $text = 'updated repro steps';
                                    $icon = '🐛';
                                    $iconBg = 'bg-red-100';
                                    $iconText = 'text-red-700';
                                    break;
                                case 'updated':
                                    if ($activity->field === 'name') {
                                        $text = 'changed title from "' . \Illuminate\Support\Str::limit($activity->old_value ?? '', 30) . '" to "' . \Illuminate\Support\Str::limit($activity->new_value ?? '', 30) . '"';
                                        $icon = '✏️';
                                        $iconBg = 'bg-blue-100';
                                        $iconText = 'text-blue-700';
                                    } elseif ($activity->field === 'item_type') {
                                        $text = 'changed type from "' . ($activity->old_value ?? 'task') . '" to "' . ($activity->new_value ?? 'task') . '"';
                                        $icon = '🔄';
                                        $iconBg = 'bg-purple-100';
                                        $iconText = 'text-purple-700';
                                    } else {
                                        $text = 'updated ' . $activity->field;
                                    }
                                    break;
                            }
                            
                            $historyItems->push([
                                'type' => $activity->type,
                                'user' => $activity->user,
                                'text' => $text,
                                'timestamp' => $activity->created_at,
                                'icon' => $icon,
                                'iconBg' => $iconBg,
                                'iconText' => $iconText,
                            ]);
                        }
                        
                        // Add comments
                        foreach ($item->comments as $comment) {
                            $historyItems->push([
                                'type' => 'comment',
                                'user' => $comment->user,
                                'text' => 'commented',
                                'body' => $comment->body,
                                'timestamp' => $comment->created_at,
                                'icon' => '💬',
                                'iconBg' => 'bg-purple-100',
                                'iconText' => 'text-purple-700',
                            ]);
                        }
                        
                        // Sort by timestamp (newest first)
                        $historyItems = $historyItems->sortByDesc('timestamp');
                    @endphp
                    <!-- History Tab -->
                    <div class="space-y-2 min-h-full">
                        @forelse($historyItems as $history)
                        <div class="flex items-start gap-2 border-b border-gray-100 pb-2 last:border-0">
                            @if($history['user'])
                                @php
                                    $historyUser = $history['user'];
                                    $photoUrl = route('api.users.photo', $historyUser);
                                    $nameParts = explode(' ', trim($historyUser->name));
                                    $initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr($nameParts[count($nameParts) - 1], 0, 1) : ''));
                                @endphp
                                <div class="relative h-6 w-6 shrink-0 overflow-hidden rounded-full bg-gray-300">
                                    <img src="{{ $photoUrl }}" alt="{{ $historyUser->name }}" class="h-full w-full object-cover" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
                                    <div class="hidden h-full w-full items-center justify-center bg-gray-400 text-xs font-medium text-white">
                                        {{ $initials }}
                                    </div>
                                </div>
                            @else
                                <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full {{ $history['iconBg'] }} text-xs font-medium {{ $history['iconText'] }}">
                                    {{ $history['icon'] }}
                                </div>
                            @endif
                            <div class="flex-1 min-w-0">
                                <p class="text-xs text-gray-900">
                                    @if($history['user'])
                                        <span class="font-medium">{{ $history['user']->name }}</span> {{ $history['text'] }}
                                    @else
                                        {{ $history['text'] }}
                                    @endif
                                </p>
                                @if(isset($history['body']))
                                    <p class="mt-0.5 text-xs text-gray-600">{!! preg_replace('/@(\w+)/', '<span class="text-blue-600 font-medium">@$1</span>', e(\Illuminate\Support\Str::limit($history['body'], 100))) !!}</p>
                                @endif
                                <p class="mt-0.5 text-xs text-gray-400">{{ $history['timestamp']->format('M j, Y g:i A') }}</p>
                            </div>
                        </div>
                        @empty
                        <div class="py-6 text-center">
                            <p class="text-xs text-gray-500">No history available</p>
                        </div>
                        @endforelse
                    </div>
                </div>
                </div>
                @else
                <div class="flex-1 p-4 text-sm text-gray-500">Item not found.</div>
                @endif
            </div>
        </div>
    @endif
</div>

<script>
(function() {
    if (typeof window.initMentions === 'undefined') {
        window.initMentions = true;
        
        let mentionUsers = [];
        let mentionDropdown = null;
        let currentTextarea = null;
        let currentCursorPos = 0;
        let currentSearch = '';
        
        function initMentionAutocomplete() {
            document.addEventListener('input', function(e) {
                if (!e.target.classList.contains('js-mention-textarea')) return;
                
                const textarea = e.target;
                const boardId = textarea.getAttribute('data-mention-board-id');
                if (!boardId) return;
                
                const cursorPos = textarea.selectionStart;
                const text = textarea.value;
                const textBeforeCursor = text.substring(0, cursorPos);
                const match = textBeforeCursor.match(/@(\w*)$/);
                
                if (match) {
                    currentTextarea = textarea;
                    currentCursorPos = cursorPos;
                    currentSearch = match[1].toLowerCase();
                    
                    if (!mentionDropdown) {
                        createMentionDropdown();
                    }
                    
                    loadMentionUsers(boardId);
                    showMentionDropdown(textarea, match.index);
                } else {
                    hideMentionDropdown();
                }
            });
            
            // Also track cursor position changes
            document.addEventListener('keyup', function(e) {
                if (!e.target.classList.contains('js-mention-textarea')) return;
                if (!mentionDropdown || !mentionDropdown.classList.contains('show')) return;
                
                const textarea = e.target;
                const cursorPos = textarea.selectionStart;
                const text = textarea.value;
                const textBeforeCursor = text.substring(0, cursorPos);
                const match = textBeforeCursor.match(/@(\w*)$/);
                
                if (match) {
                    currentCursorPos = cursorPos;
                    showMentionDropdown(textarea, match.index);
                }
            });
            
            document.addEventListener('keydown', function(e) {
                // Only handle if dropdown is visible and user is typing in a mention textarea
                if (!mentionDropdown || !mentionDropdown.classList.contains('show') || !currentTextarea) return;
                
                // Check if the event target is the current textarea
                if (e.target !== currentTextarea && !currentTextarea.contains(e.target)) return;
                
                const items = mentionDropdown.querySelectorAll('.mention-item');
                if (items.length === 0) return;
                
                const selected = mentionDropdown.querySelector('.mention-item.selected');
                let nextSelected = null;
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    e.stopPropagation();
                    if (selected) {
                        selected.classList.remove('selected');
                        nextSelected = selected.nextElementSibling || items[0];
                    } else {
                        nextSelected = items[0];
                    }
                    if (nextSelected) {
                        nextSelected.classList.add('selected');
                        nextSelected.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    e.stopPropagation();
                    if (selected) {
                        selected.classList.remove('selected');
                        nextSelected = selected.previousElementSibling || items[items.length - 1];
                    } else {
                        nextSelected = items[items.length - 1];
                    }
                    if (nextSelected) {
                        nextSelected.classList.add('selected');
                        nextSelected.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    }
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    e.stopPropagation();
                    if (selected) {
                        insertMention(selected.dataset.name);
                    } else if (items.length > 0) {
                        // If no selection, select first item
                        insertMention(items[0].dataset.name);
                    }
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    e.stopPropagation();
                    hideMentionDropdown();
                }
            });
            
            document.addEventListener('click', function(e) {
                if (mentionDropdown && !mentionDropdown.contains(e.target) && e.target !== currentTextarea) {
                    hideMentionDropdown();
                }
            });
        }
        
        function createMentionDropdown() {
            mentionDropdown = document.createElement('div');
            mentionDropdown.className = 'mention-dropdown';
            mentionDropdown.style.cssText = 'display: none; position: fixed; background: white; border: 1px solid #e0e0e0; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); max-height: 200px; overflow-y: auto; z-index: 10000; min-width: 200px;';
            document.body.appendChild(mentionDropdown);
        }
        
        function loadMentionUsers(boardId) {
            fetch('/api/boards/' + boardId + '/mentionable-users')
                .then(r => r.json())
                .then(users => {
                    mentionUsers = users;
                    renderMentionDropdown();
                })
                .catch(() => {
                    mentionUsers = [];
                    renderMentionDropdown();
                });
        }
        
        function renderMentionDropdown() {
            if (!mentionDropdown) return;
            
            const filtered = mentionUsers.filter(u => 
                !currentSearch || u.search.includes(currentSearch)
            ).slice(0, 10);
            
            if (filtered.length === 0) {
                mentionDropdown.innerHTML = '<div style="padding: 8px 12px; color: #999; font-size: 12px;">No users found</div>';
                return;
            }
            
            mentionDropdown.innerHTML = filtered.map((user, idx) => `
                <div class="mention-item" data-name="${user.name}" style="padding: 8px 12px; cursor: pointer; font-size: 13px; ${idx === 0 ? 'background: #f0f0f0;' : ''}" ${idx === 0 ? 'class="mention-item selected"' : 'class="mention-item"'} onclick="window.insertMention('${user.name.replace(/'/g, "\\'")}')">
                    ${user.name}
                </div>
            `).join('');
            
            mentionDropdown.querySelectorAll('.mention-item').forEach(item => {
                item.addEventListener('mouseenter', function() {
                    mentionDropdown.querySelectorAll('.mention-item').forEach(i => i.classList.remove('selected'));
                    this.classList.add('selected');
                });
            });
        }
        
        function showMentionDropdown(textarea, startPos) {
            if (!mentionDropdown) return;
            
            // Get the textarea's position relative to the viewport
            const rect = textarea.getBoundingClientRect();
            
            // Calculate cursor position more accurately
            const text = textarea.value.substring(0, startPos);
            const lines = text.split('\n');
            const currentLine = lines.length - 1;
            
            // Get computed styles for accurate measurements
            const textareaStyle = window.getComputedStyle(textarea);
            const lineHeight = parseFloat(textareaStyle.lineHeight) || 20;
            const paddingTop = parseFloat(textareaStyle.paddingTop) || 0;
            const paddingLeft = parseFloat(textareaStyle.paddingLeft) || 0;
            const borderTop = parseFloat(textareaStyle.borderTopWidth) || 0;
            
            // Calculate the exact position of the "@" symbol
            // Get the text before cursor on the current line to calculate horizontal offset
            const currentLineText = lines[currentLine] || '';
            const atIndex = currentLineText.lastIndexOf('@');
            const textBeforeAt = currentLineText.substring(0, atIndex);
            
            // Create a temporary span to measure text width accurately
            const measureSpan = document.createElement('span');
            measureSpan.style.visibility = 'hidden';
            measureSpan.style.position = 'absolute';
            measureSpan.style.whiteSpace = 'pre';
            measureSpan.style.font = textareaStyle.font;
            measureSpan.style.fontSize = textareaStyle.fontSize;
            measureSpan.style.fontFamily = textareaStyle.fontFamily;
            measureSpan.style.fontWeight = textareaStyle.fontWeight;
            measureSpan.style.letterSpacing = textareaStyle.letterSpacing;
            measureSpan.style.padding = '0';
            measureSpan.style.margin = '0';
            measureSpan.style.border = 'none';
            measureSpan.textContent = textBeforeAt;
            document.body.appendChild(measureSpan);
            const textWidth = measureSpan.offsetWidth;
            document.body.removeChild(measureSpan);
            
            // Calculate cursor Y position - position at the line where "@" appears
            // Use the current line (where "@" is), not the next line
            const cursorY = rect.top + paddingTop + borderTop + (currentLine * lineHeight) + (lineHeight * 0.8);
            const cursorX = rect.left + paddingLeft + textWidth;
            
            // Use fixed positioning relative to viewport
            mentionDropdown.style.position = 'fixed';
            
            // Position dropdown right below the cursor, but ensure it stays within viewport
            const dropdownHeight = 200; // max-height
            const spaceBelow = window.innerHeight - cursorY;
            const spaceAbove = cursorY;
            
            let topPosition;
            if (spaceBelow >= dropdownHeight + 10) {
                // Enough space below, place directly below cursor
                topPosition = cursorY + 2;
            } else if (spaceAbove >= dropdownHeight + 10) {
                // Not enough space below, but enough above, place above cursor
                topPosition = cursorY - dropdownHeight - 2;
            } else {
                // Not enough space either way, place where there's more space
                topPosition = spaceBelow > spaceAbove ? cursorY + 2 : Math.max(10, cursorY - dropdownHeight - 2);
            }
            
            mentionDropdown.style.top = Math.max(10, Math.min(topPosition, window.innerHeight - dropdownHeight - 10)) + 'px';
            mentionDropdown.style.left = Math.max(10, Math.min(cursorX, window.innerWidth - 220)) + 'px';
            mentionDropdown.style.display = 'block';
            mentionDropdown.classList.add('show');
            
            renderMentionDropdown();
        }
        
        function hideMentionDropdown() {
            if (mentionDropdown) {
                mentionDropdown.style.display = 'none';
                mentionDropdown.classList.remove('show');
            }
            currentTextarea = null;
        }
        
        window.insertMention = function(name) {
            if (!currentTextarea) return;
            
            const text = currentTextarea.value;
            const textBeforeCursor = text.substring(0, currentCursorPos);
            const match = textBeforeCursor.match(/@(\w*)$/);
            
            if (match) {
                const start = currentCursorPos - match[0].length;
                const newText = text.substring(0, start) + '@' + name + ' ' + text.substring(currentCursorPos);
                currentTextarea.value = newText;
                const newPos = start + name.length + 2;
                currentTextarea.setSelectionRange(newPos, newPos);
                currentTextarea.focus();
            }
            
            hideMentionDropdown();
        };
        
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initMentionAutocomplete);
        } else {
            initMentionAutocomplete();
        }
    }
})();
</script>

<style>
.mention-item:hover {
    background: #f0f0f0 !important;
}
.mention-item.selected {
    background: #e3f2fd !important;
}
.mention-dropdown {
    scrollbar-width: thin;
    scrollbar-color: rgb(156 163 175) rgb(243 244 246);
}
.mention-dropdown::-webkit-scrollbar {
    width: 6px;
}
.mention-dropdown::-webkit-scrollbar-track {
    background: rgb(243 244 246);
    border-radius: 3px;
}
.mention-dropdown::-webkit-scrollbar-thumb {
    background: rgb(156 163 175);
    border-radius: 3px;
}
.mention-dropdown::-webkit-scrollbar-thumb:hover {
    background: rgb(107 114 128);
}
</style>
