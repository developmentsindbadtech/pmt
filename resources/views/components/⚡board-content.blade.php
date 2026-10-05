<?php

use App\Models\Board;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public int $boardId;

    public string $view = 'kanban';

    /** Kanban column search (lives on parent so wire:model survives parent morphs). */
    public string $filterSearch = '';

    public ?int $selectedItemId = null;

    /** @var int|null Filter by assignee (null = All or Unassigned) */
    public ?int $filterAssigneeId = null;

    /** @var bool When true, filter to items with no assignee */
    public bool $filterUnassigned = false;

    /** @var string|null Filter by type: 'task'|'bug'|null = All */
    public ?string $filterType = null;

    /** @var array<int> Filter by status/group IDs — only show these columns (empty = all) */
    public array $filterGroupIds = [];

    /** active (default) | archived */
    public string $itemVisibility = 'active';

    /** When active: hide items in Done/Closed columns (column stays for drop-to-complete). */
    public bool $showDone = false;

    public string $activeTab = 'details';

    public function mount(
        int $boardId,
        string $view = 'kanban',
        ?int $filterAssigneeId = null,
        bool $filterUnassigned = false,
        ?string $filterType = null,
        array $filterGroupIds = [],
        ?int $selectedItemId = null,
        ?string $itemVisibility = null,
        ?bool $showDone = null,
    ): void {
        $this->boardId = $boardId;
        $this->view = $view;
        $this->filterAssigneeId = $filterAssigneeId;
        $this->filterUnassigned = $filterUnassigned;
        $this->filterType = $filterType;
        $this->filterGroupIds = $filterGroupIds;
        // Use provided selectedItemId or check query parameter (backward compatibility)
        $this->selectedItemId = $selectedItemId ?? (request()->has('item') ? (int) request('item') : null);

        // Restore Active/Archived + Show Done across Kanban/List navigations.
        $sessionMode = session($this->visibilitySessionKey('mode'));
        $sessionShowDone = session($this->visibilitySessionKey('show_done'));
        $requestMode = request()->input('visibility', $itemVisibility);
        $requestShowDone = request()->has('show_done')
            ? filter_var(request()->input('show_done'), FILTER_VALIDATE_BOOLEAN)
            : $showDone;

        $this->itemVisibility = in_array($requestMode, ['active', 'archived'], true)
            ? $requestMode
            : (in_array($sessionMode, ['active', 'archived'], true) ? $sessionMode : 'active');
        $this->showDone = $requestShowDone !== null
            ? (bool) $requestShowDone
            : (bool) ($sessionShowDone ?? false);

        if ($this->itemVisibility === 'archived') {
            $this->showDone = true;
        }
        $this->persistVisibility();
    }

    public function setItemVisibility(string $visibility): void
    {
        $this->itemVisibility = in_array($visibility, ['active', 'archived'], true) ? $visibility : 'active';
        if ($this->itemVisibility === 'archived') {
            $this->showDone = true;
        }
        $this->persistVisibility();
    }

    public function toggleShowDone(): void
    {
        if ($this->itemVisibility !== 'active') {
            return;
        }
        $this->showDone = ! $this->showDone;
        $this->persistVisibility();
    }

    private function visibilitySessionKey(string $suffix): string
    {
        return 'board.'.$this->boardId.'.visibility.'.$suffix;
    }

    private function persistVisibility(): void
    {
        session([
            $this->visibilitySessionKey('mode') => $this->itemVisibility,
            $this->visibilitySessionKey('show_done') => $this->showDone,
        ]);
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

    public function renameBoard(string $name): void
    {
        // Only admins manage board structure (create/delete/rename).
        if (! auth()->user()?->is_admin) {
            return;
        }
        $name = trim($name);
        if ($name === '') {
            return;
        }
        Board::whereKey($this->boardId)->update(['name' => mb_substr($name, 0, 255)]);
        unset($this->board);
    }

    public function archiveItem(int $itemId): void
    {
        $item = Item::query()->where('board_id', $this->boardId)->find($itemId);
        if (! $item) {
            return;
        }
        $item->archive();
        if ($this->selectedItemId === $itemId) {
            $this->selectedItemId = null;
        }
        unset($this->item, $this->board);
        session()->flash('success', 'Item archived.');
    }

    public function unarchiveItem(int $itemId): void
    {
        $item = Item::query()->where('board_id', $this->boardId)->find($itemId);
        if (! $item) {
            return;
        }
        $item->unarchive();
        unset($this->item, $this->board);
        session()->flash('success', 'Item restored.');
    }

    public function getBoardProperty(): ?Board
    {
        return Board::with('groups')
            ->where('id', $this->boardId)
            ->first();
    }

    public function getItemProperty(): ?Item
    {
        if (! $this->selectedItemId) {
            return null;
        }
        
        // Always load creator to avoid N+1 queries, but it's lightweight
        $with = [
            'assignee:id,name',
            'group:id,name,board_id',
            'parent:id,number,name',
            'children' => fn ($q) => $q->orderBy('number')->select(['id', 'parent_id', 'number', 'name']),
            'comments' => fn ($q) => $q->with('user:id,name')->orderByDesc('created_at')->limit(30),
            'creator:id,name',
        ];
        if ($this->activeTab === 'history') {
            $with['activities'] = fn ($q) => $q->with('user:id,name')->orderByDesc('created_at')->limit(40);
        }

        return Item::with($with)
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
        $version = (int) Cache::get('pmt.nav.version', 1);

        return Cache::remember("pmt.board-users.{$board->id}.{$version}", 3600, function () use ($board) {
            return User::query()
                ->where(function ($query) use ($board) {
                    $query->whereHas('boards', function ($q) use ($board) {
                        $q->where('boards.id', $board->id);
                    })->orWhere('is_admin', true);
                })
                ->orderBy('name', 'asc')
                ->get(['id', 'name', 'is_admin']);
        });
    }
};
?>

@php
    $board = $this->board;
    $item = $this->selectedItemId ? $this->item : null;
    $users = $board ? $this->users : collect();
@endphp
<div x-data x-on:open-item.window="$wire.openItem($event.detail.itemId)" class="flex h-full flex-col">
    <div class="mb-4 flex shrink-0 items-center justify-between">
        <div>
            <a href="{{ route('boards.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Boards</a>
            @if($board && auth()->user()?->is_admin)
                <div x-data="{ editing: false }" class="mt-1">
                    <h1 x-show="!editing" @click="editing = true; $nextTick(() => { $refs.boardTitle.focus(); $refs.boardTitle.select(); })" class="-mx-1 cursor-text rounded-lg px-1 text-2xl font-semibold tracking-tight text-gray-900 hover:bg-gray-100" title="Click to rename board">{{ $board->name }}</h1>
                    <input
                        x-show="editing" x-cloak x-ref="boardTitle" type="text" value="{{ $board->name }}"
                        @keydown.enter.prevent="$refs.boardTitle.blur()"
                        @keydown.escape="editing = false"
                        @blur="editing = false; $wire.renameBoard($refs.boardTitle.value)"
                        class="-mx-1 w-full max-w-md rounded border border-gray-300 px-1 text-2xl font-semibold text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    />
                </div>
            @else
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900">{{ $board?->name }}</h1>
            @endif
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
                <form action="{{ route('boards.filters.apply', $board) }}" method="POST" class="flex items-center gap-2" onchange="this.requestSubmit()">
                    @csrf
                    <input type="hidden" name="view" value="{{ $view }}" />
                    <label class="text-sm text-gray-600">Status</label>
                    <div class="relative" x-data="{ open: false }">
                        <button type="button" @click="open = !open" class="rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-sm text-gray-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                            {{ empty($filterGroupIds) ? 'All' : count($filterGroupIds) . ' selected' }}
                        </button>
                        <div x-show="open" @click.outside="open = false" x-cloak class="absolute left-0 top-full z-50 mt-1 w-44 rounded-md border border-gray-200 bg-white py-1 shadow-lg">
                            @foreach($board->groups as $g)
                                <label class="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm hover:bg-gray-50">
                                    <input type="checkbox" name="status[]" value="{{ $g->id }}" {{ in_array($g->id, $filterGroupIds ?? []) ? 'checked' : '' }} class="rounded border-gray-300" />
                                    <span>{{ $g->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <label class="text-sm text-gray-600">Assignee</label>
                    <select name="assignee" class="rounded-md border border-gray-300 bg-white text-sm text-gray-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500" x-model="assigneeSelect">
                        <option value="">All</option>
                        <option value="unassigned">Unassigned</option>
                        @foreach($users as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                    <label class="text-sm text-gray-600">Type</label>
                    <select name="type" class="rounded-md border border-gray-300 bg-white text-sm text-gray-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500" x-model="typeSelect">
                        <option value="">All</option>
                        <option value="task">Task</option>
                        <option value="bug">Bug</option>
                    </select>
                </form>
                <form action="{{ route('boards.filters.reset', $board) }}" method="POST" class="inline">
                    @csrf
                    <input type="hidden" name="view" value="{{ $view }}" />
                    <button type="submit" class="rounded-md border border-gray-300 bg-gray-100 px-2.5 py-1.5 text-sm text-gray-600 hover:bg-gray-200 hover:text-gray-800">Reset</button>
                </form>
            </div>
            @endif
            <div class="inline-flex items-center rounded-md border border-gray-200 bg-gray-50 p-0.5">
                <button type="button" wire:click="setItemVisibility('active')" class="rounded px-2.5 py-1 text-xs font-medium {{ $itemVisibility === 'active' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800' }}">Active</button>
                <button type="button" wire:click="setItemVisibility('archived')" class="rounded px-2.5 py-1 text-xs font-medium {{ $itemVisibility === 'archived' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800' }}">Archived</button>
            </div>
            @if($itemVisibility === 'active')
                <button
                    type="button"
                    wire:click="toggleShowDone"
                    class="rounded-md border px-2.5 py-1.5 text-xs font-medium {{ $showDone ? 'border-blue-200 bg-blue-50 text-blue-800' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50' }}"
                    title="{{ $showDone ? 'Hide items in Done columns' : 'Show items in Done columns' }}"
                >{{ $showDone ? 'Hide Done' : 'Show Done' }}</button>
            @endif
            @php
                $assigneeParam = $filterUnassigned ? 'unassigned' : $filterAssigneeId;
                $routeParams = array_filter([
                    'board' => $board,
                    'assignee' => $assigneeParam,
                    'type' => $filterType,
                    'status' => ! empty($filterGroupIds) ? $filterGroupIds : null,
                    'visibility' => $itemVisibility !== 'active' ? $itemVisibility : null,
                    'show_done' => ($itemVisibility === 'active' && $showDone) ? 1 : null,
                ], fn ($v) => $v !== null && $v !== '');
            @endphp
            @if($view !== 'table')
                <input type="search" wire:model.live.debounce.400ms="filterSearch" placeholder="Search" autocomplete="off" class="w-36 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-sm text-gray-800 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none focus:ring-1 focus:ring-gray-300" />
            @endif
            <a href="{{ route('boards.show', array_merge($routeParams, ['view' => 'kanban'])) }}" class="rounded-md px-3 py-1.5 text-sm {{ $view === 'kanban' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">Kanban</a>
            <a href="{{ route('boards.show', array_merge($routeParams, ['view' => 'table'])) }}" class="rounded-md px-3 py-1.5 text-sm {{ $view === 'table' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">List</a>
            <a href="{{ route('boards.analytics', $board) }}" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Analytics</a>
            <div class="relative" x-data="{ open: false }">
                <button type="button" @click="open = !open" class="rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50" title="More">⋯</button>
                <div x-show="open" @click.outside="open = false" x-cloak class="absolute right-0 z-50 mt-1 w-44 rounded-md border border-gray-200 bg-white py-1 shadow-lg">
                    <a href="{{ route('boards.export-csv', $board) }}" class="block px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Download CSV</a>
                    @if(auth()->user()->is_admin)
                        <a href="{{ route('user-management.index') }}#boards" class="block px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Manage access</a>
                        <form action="{{ route('boards.destroy', $board) }}" method="POST" onsubmit="return confirm('Delete this board and all its tasks?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="block w-full px-3 py-1.5 text-left text-sm text-red-600 hover:bg-red-50">Delete board</button>
                        </form>
                    @endif
                </div>
            </div>
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
        @livewire('table-view', [
            'boardId' => $boardId,
            'filterAssigneeId' => $filterAssigneeId,
            'filterUnassigned' => $filterUnassigned,
            'filterType' => $filterType,
            'filterGroupIds' => $filterGroupIds,
            'itemVisibility' => $itemVisibility,
            'showDone' => $showDone,
        ], key('table-'.$boardId.'-'.$itemVisibility.'-'.($showDone ? '1' : '0')))
    @else
        <div class="flex min-h-0 flex-1 flex-col gap-3 rounded-xl bg-gray-900 p-3 ring-1 ring-white/10">
            <div class="flex min-h-0 flex-1 flex-col">
                @livewire('kanban-view', [
                    'boardId' => $boardId,
                    'filterAssigneeId' => $filterAssigneeId,
                    'filterUnassigned' => $filterUnassigned,
                    'filterType' => $filterType,
                    'filterGroupIds' => $filterGroupIds,
                    'filterSearch' => $filterSearch,
                    'itemVisibility' => $itemVisibility,
                    'showDone' => $showDone,
                ], key('kanban-'.$boardId.'-'.$itemVisibility.'-'.($showDone ? '1' : '0')))
            </div>
        </div>
    @endif

    @if($selectedItemId)
        {{-- Hide immediately on click so a failed Livewire update cannot leave this overlay blocking the board. --}}
        <div
            wire:key="detail-shell"
            x-data="{
                dismiss() {
                    this.$root.style.display = 'none';
                    this.$root.style.pointerEvents = 'none';
                    const url = @js(route('boards.show', ['board' => $board, 'view' => $view]));
                    if (url && history.replaceState) history.replaceState({}, '', url);
                    $wire.closePanel();
                }
            }"
            x-on:keydown.escape.window="dismiss()"
        >
        <div
            class="fixed inset-0 z-40 bg-black/40 cursor-default"
            role="button"
            tabindex="0"
            aria-label="Close panel"
            x-on:click="dismiss()"
        ></div>
        {{-- Right-side panel: minimalist, compact, professional --}}
        <div class="item-detail-panel fixed inset-y-0 right-0 z-50 w-full max-w-md border-l border-gray-200 bg-gray-50/95 shadow-xl backdrop-blur-sm" role="dialog" aria-label="Item details">
            <div class="flex h-full flex-col bg-white">
                <div class="flex shrink-0 items-center justify-between border-b border-gray-100 px-4 py-2.5">
                    <h2 class="text-sm font-semibold tracking-tight text-gray-800">
                        {{ $item ? ($item->isBug() ? 'Bug' : 'Task') . ' #' . $item->number : '' }}
                    </h2>
                    <button type="button" x-on:click="dismiss()" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-gray-300 bg-gray-50 text-base font-medium text-gray-600 hover:border-gray-400 hover:bg-gray-100 hover:text-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-1 transition-colors" title="Close" aria-label="Close">&times;</button>
                </div>
                @if($item && $board)
                <div class="flex flex-col flex-1 min-h-0" x-data="{ 
                    init() {
                        this.$watch('$wire.activeTab', () => {
                            if (this.$refs.scrollContainer) this.$refs.scrollContainer.scrollTop = 0;
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
                <div class="min-w-0 flex-1 overflow-y-auto overflow-x-hidden px-4 py-3" 
                     x-ref="scrollContainer"
                     style="scrollbar-width: thin; scrollbar-color: rgb(156 163 175) rgb(243 244 246);">
                    <div x-show="$wire.activeTab === 'details'" style="display: {{ $activeTab === 'details' ? 'block' : 'none' }};">
                    <form action="{{ route('items.update', [$board, $item]) }}" method="POST" class="min-w-0 space-y-3">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="view" value="{{ $view }}" />
                        @if($view !== 'kanban')
                            <input type="hidden" name="return_item" value="1" />
                        @endif
                        @php
                            $field = 'mt-1 w-full rounded border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none focus:ring-1 focus:ring-gray-400';
                            $label = 'block text-[11px] font-medium uppercase tracking-wide text-gray-500';
                        @endphp
                        <div>
                            <label class="{{ $label }}">Title</label>
                            <input type="text" name="name" value="{{ old('name', $item->name) }}" required class="{{ $field }}" placeholder="Short title" />
                            @error('name') <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="{{ $label }}">Type</label>
                                <select name="item_type" class="{{ $field }}">
                                    <option value="task" @selected(old('item_type', $item->item_type) === 'task')>Task</option>
                                    <option value="bug" @selected(old('item_type', $item->item_type) === 'bug')>Bug</option>
                                </select>
                            </div>
                            @if($board->groups->isNotEmpty())
                            <div>
                                <label class="{{ $label }}">Status</label>
                                <select name="group_id" class="{{ $field }}">
                                    @foreach($board->groups as $g)
                                        <option value="{{ $g->id }}" @selected((string) old('group_id', $item->group_id) === (string) $g->id)>{{ $g->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @endif
                            <div>
                                <label class="{{ $label }}">Priority</label>
                                <select name="priority" class="{{ $field }}">
                                    @foreach(\App\Models\Item::priorityOptions() as $val => $opt)
                                        <option value="{{ $val }}" @selected(old('priority', $item->priority ?? 'medium') === $val)>{{ $opt }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="{{ $label }}">Due</label>
                                <input type="date" name="due_at" value="{{ old('due_at', $item->due_at?->format('Y-m-d')) }}" class="{{ $field }}" />
                            </div>
                            <div>
                                <label class="{{ $label }}">Assignee</label>
                                <select name="assignee_id" class="{{ $field }}">
                                    <option value="">None</option>
                                    @foreach($users as $u)
                                        <option value="{{ $u->id }}" @selected((string) old('assignee_id', $item->assignee_id) === (string) $u->id)>{{ $u->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @if($item->isBug())
                            <div>
                                <label class="{{ $label }}">Severity</label>
                                <select name="severity" class="{{ $field }}">
                                    @foreach(\App\Models\Item::severityOptions() as $val => $opt)
                                        <option value="{{ $val }}" @selected(old('severity', $item->severity ?? 'major') === $val)>{{ $opt }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @else
                            <input type="hidden" name="severity" value="" />
                            @endif
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="{{ $label }}">Dev tag</label>
                                <select name="dev_tag" class="{{ $field }}">
                                    <option value="">None</option>
                                    @foreach(\App\Models\Item::devTagOptions() as $val => $opt)
                                        <option value="{{ $val }}" @selected((string) old('dev_tag', $item->dev_tag ?? '') === (string) $val)>{{ $opt }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @php
                                $boardItemsForParent = \App\Models\Item::where('board_id', $board->id)->get(['id', 'parent_id', 'number', 'name']);
                                $parentCandidates = \App\Models\Item::filterValidParentCandidates($item, $boardItemsForParent);
                            @endphp
                            <div class="min-w-0">
                                <label class="{{ $label }}">Related to</label>
                                <select name="parent_id" class="{{ $field }}">
                                    <option value="">None</option>
                                    @foreach($parentCandidates as $p)
                                        <option value="{{ $p->id }}" @selected((string) old('parent_id', $item->parent_id ?? '') === (string) $p->id)>#{{ $p->number }} {{ \Illuminate\Support\Str::limit($p->name, 40) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        @if($item->children->isNotEmpty())
                        <div class="min-w-0">
                            <span class="{{ $label }}">Sub-items</span>
                            <ul class="mt-1 space-y-1">
                                @foreach($item->children as $child)
                                    <li class="min-w-0">
                                        <button type="button" wire:click="openItem({{ $child->id }})" class="block w-full truncate text-left text-sm text-blue-600 hover:underline">#{{ $child->number }} {{ $child->name }}</button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                        @endif
                        @if($item->isTask())
                        <div>
                            <label class="{{ $label }}">Description</label>
                            <textarea name="description" rows="5" data-mention-board-id="{{ $board->id }}" class="js-mention-textarea {{ $field }} min-h-[7rem] resize-y" placeholder="What needs to be done. Use @ to mention someone.">{{ old('description', $item->description) }}</textarea>
                        </div>
                        @else
                        <div>
                            <label class="{{ $label }}">Repro steps</label>
                            <textarea name="repro_steps" rows="5" data-mention-board-id="{{ $board->id }}" class="js-mention-textarea {{ $field }} min-h-[7rem] resize-y" placeholder="How to reproduce. Use @ to mention someone.">{{ old('repro_steps', $item->repro_steps) }}</textarea>
                        </div>
                        @endif
                        <button type="submit" class="w-full rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">Save</button>
                    </form>
                    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4">
                        @if($item->isArchived())
                            <button
                                type="button"
                                wire:click="unarchiveItem({{ $item->id }})"
                                wire:confirm="Restore this item to the active board?"
                                class="rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-emerald-50 hover:text-emerald-800"
                            >Restore</button>
                        @else
                            <button
                                type="button"
                                wire:click="archiveItem({{ $item->id }})"
                                wire:confirm="Archive this item? It will leave the active board."
                                class="rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-amber-50 hover:text-amber-800"
                            >Archive</button>
                        @endif
                        <form action="{{ route('items.destroy', [$board, $item]) }}" method="POST" class="inline" onsubmit="return confirm('Permanently delete this item? This cannot be undone.');">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="view" value="{{ $view }}" />
                            <button type="submit" class="rounded-md px-3 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50">Delete</button>
                        </form>
                        @if($item->isArchived())
                            <span class="text-xs text-amber-700">Archived {{ $item->archived_at?->diffForHumans() }}</span>
                        @endif
                    </div>
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
                    @if($activeTab === 'history')
                    @php
                        if (! $item->relationLoaded('activities')) {
                            $item->load(['activities' => fn ($q) => $q->with('user:id,name')->orderByDesc('created_at')->limit(40)]);
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
                                    } elseif ($activity->field === 'priority') {
                                        $text = 'changed priority from "' . ucfirst($activity->old_value ?? 'medium') . '" to "' . ucfirst($activity->new_value ?? 'medium') . '"';
                                        $icon = '⚡';
                                        $iconBg = 'bg-amber-100';
                                        $iconText = 'text-amber-700';
                                    } elseif ($activity->field === 'severity') {
                                        $text = 'changed severity from "' . ucfirst($activity->old_value ?? '—') . '" to "' . ucfirst($activity->new_value ?? '—') . '"';
                                        $icon = '🐛';
                                        $iconBg = 'bg-red-100';
                                        $iconText = 'text-red-700';
                                    } elseif ($activity->field === 'due_at') {
                                        $old = $activity->old_value ? \Carbon\Carbon::parse($activity->old_value)->format('M j, Y') : 'None';
                                        $new = $activity->new_value ? \Carbon\Carbon::parse($activity->new_value)->format('M j, Y') : 'None';
                                        $text = 'changed due date from "' . $old . '" to "' . $new . '"';
                                        $icon = '📅';
                                        $iconBg = 'bg-indigo-100';
                                        $iconText = 'text-indigo-700';
                                    } elseif ($activity->field === 'dev_tag') {
                                        $old = $activity->old_value ?? 'None';
                                        $new = $activity->new_value ?? 'None';
                                        $text = 'changed dev tag from "' . $old . '" to "' . $new . '"';
                                        $icon = '🏷️';
                                        $iconBg = 'bg-violet-100';
                                        $iconText = 'text-violet-700';
                                    } elseif ($activity->field === 'parent_id') {
                                        $old = $activity->old_value ?? 'None';
                                        $new = $activity->new_value ?? 'None';
                                        $text = 'changed parent from "' . $old . '" to "' . $new . '"';
                                        $icon = '🔗';
                                        $iconBg = 'bg-sky-100';
                                        $iconText = 'text-sky-700';
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
                    @endif
                    </div>
                </div>
                </div>
                @else
                <div class="flex-1 p-4 text-sm text-gray-500">Item not found.</div>
                @endif
            </div>
        </div>
        </div>
    @endif

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
</div>
