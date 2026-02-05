<?php

use App\Models\Board;
use App\Models\Item;
use App\Models\User;
use Livewire\Component;

new class extends Component
{
    public int $boardId;

    public ?int $itemId = null;

    public function mount(int $boardId, ?int $itemId = null): void
    {
        $this->boardId = $boardId;
        $this->itemId = $itemId;
    }

    public function getBoardProperty(): ?Board
    {
        return Board::with(['columns' => fn ($q) => $q->orderBy('position'), 'groups', 'users'])
            ->where('id', $this->boardId)
            ->first();
    }

    public function getItemProperty(): ?Item
    {
        if (! $this->itemId) {
            return null;
        }
        return Item::with(['assignee', 'group', 'comments.user', 'creator'])
            ->where('board_id', $this->boardId)
            ->find($this->itemId);
    }
    
    public string $activeTab = 'details';
    
    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
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
    $item = $this->item;
    $users = $this->users;
@endphp
<div class="fixed inset-y-0 right-0 z-50 w-full max-w-lg border-l border-gray-200 bg-white shadow-xl sm:max-w-xl" x-data="{}">
    <div class="flex h-full flex-col">
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
            <h2 class="text-lg font-semibold text-gray-900">
                {{ $item ? ($item->isBug() ? 'Bug' : 'Task') . ' #' . $item->number : '' }}
            </h2>
            <a href="{{ $board ? route('boards.show', ['board' => $board, 'view' => request('view', $board->view_type)]) : '#' }}" class="rounded p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700" title="Close">&times;</a>
        </div>
        @if($item && $board)
        <!-- Tabs -->
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px px-4" aria-label="Tabs">
                <button 
                    wire:click="setTab('details')"
                    class="@if($activeTab === 'details') border-blue-500 text-blue-600 @else border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 @endif whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium transition-colors"
                >
                    Details
                </button>
                <button 
                    wire:click="setTab('history')"
                    class="@if($activeTab === 'history') border-blue-500 text-blue-600 @else border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 @endif whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium transition-colors"
                >
                    History
                </button>
            </nav>
        </div>
        <div class="flex-1 overflow-y-auto px-4 py-4">
            @if($activeTab === 'details')
            <form action="{{ route('items.update', [$board, $item]) }}" method="POST" class="space-y-4">
                @csrf
                @method('PUT')
                <input type="hidden" name="view" value="{{ request('view', $board->view_type) }}" />
                <input type="hidden" name="return_item" value="1" />

                <div x-data="{ editing: false }" wire:ignore.self>
                    <div class="flex items-center justify-between">
                        <label class="block text-sm font-medium text-gray-700">Type</label>
                        <button type="button" @click="editing = !editing" class="text-xs text-blue-600 hover:text-blue-700 hover:underline" x-text="editing ? 'Cancel' : 'Edit'"></button>
                    </div>
                    <div x-show="!editing" class="mt-1 text-sm text-gray-900">
                        {{ $item->item_type === 'bug' ? 'Bug' : 'Task' }}
                    </div>
                    <select x-show="editing" x-cloak name="item_type" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="task" {{ (old('item_type', $item->item_type) === 'task') ? 'selected' : '' }}>Task</option>
                        <option value="bug" {{ (old('item_type', $item->item_type) === 'bug') ? 'selected' : '' }}>Bug</option>
                    </select>
                </div>

                <div x-data="{ editing: false }" wire:ignore.self>
                    <div class="flex items-center justify-between">
                        <label class="block text-sm font-medium text-gray-700">Title</label>
                        <button type="button" @click="editing = !editing" class="text-xs text-blue-600 hover:text-blue-700 hover:underline" x-text="editing ? 'Cancel' : 'Edit'"></button>
                    </div>
                    <div x-show="!editing" class="mt-1 text-sm text-gray-900">
                        {{ $item->name }}
                    </div>
                    <input x-show="editing" x-cloak type="text" name="name" value="{{ old('name', $item->name) }}" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div x-data="{ editing: false }" wire:ignore.self>
                    <div class="flex items-center justify-between">
                        <label class="block text-sm font-medium text-gray-700">Assignee</label>
                        <button type="button" @click="editing = !editing" class="text-xs text-blue-600 hover:text-blue-700 hover:underline" x-text="editing ? 'Cancel' : 'Edit'"></button>
                    </div>
                    <div x-show="!editing" class="mt-1 text-sm text-gray-900">
                        {{ $item->assignee?->name ?? '— None —' }}
                    </div>
                    <select x-show="editing" x-cloak name="assignee_id" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">— None —</option>
                        @foreach($users as $u)
                            <option value="{{ $u->id }}" {{ (old('assignee_id', $item->assignee_id) == $u->id) ? 'selected' : '' }}>{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div x-data="{ editing: false }" wire:ignore.self>
                    <div class="flex items-center justify-between">
                        <label class="block text-sm font-medium text-gray-700">Status</label>
                        <button type="button" @click="editing = !editing" class="text-xs text-blue-600 hover:text-blue-700 hover:underline" x-text="editing ? 'Cancel' : 'Edit'"></button>
                    </div>
                    <div x-show="!editing" class="mt-1 text-sm text-gray-900">
                        {{ $item->group?->name ?? '—' }}
                    </div>
                    @if($board->groups->isNotEmpty())
                        <select x-show="editing" x-cloak name="group_id" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @foreach($board->groups as $g)
                                <option value="{{ $g->id }}" {{ (old('group_id', $item->group_id) == $g->id) ? 'selected' : '' }}>{{ $g->name }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>

                @if($item->isTask())
                    <div x-data="{ editing: false }" wire:ignore.self>
                        <div class="flex items-center justify-between">
                            <label class="block text-sm font-medium text-gray-700">Description</label>
                            <button type="button" @click="editing = !editing" class="text-xs text-blue-600 hover:text-blue-700 hover:underline" x-text="editing ? 'Cancel' : 'Edit'"></button>
                        </div>
                        <div x-show="!editing" class="mt-1 min-h-[4rem] w-full rounded border border-transparent px-3 py-2 text-sm text-gray-900 whitespace-pre-wrap">
                            @if($item->description)
                                <div class="text-left w-full">{!! preg_replace('/@(\w+)/', '<span class="text-blue-600 font-medium">@$1</span>', e($item->description)) !!}</div>
                            @else
                                <div class="text-left"><span class="text-gray-400 italic">No description</span></div>
                            @endif
                        </div>
                        <textarea x-show="editing" x-cloak name="description" rows="4" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('description', $item->description) }}</textarea>
                    </div>
                @else
                    <div x-data="{ editing: false }" wire:ignore.self>
                        <div class="flex items-center justify-between">
                            <label class="block text-sm font-medium text-gray-700">Repro steps</label>
                            <button type="button" @click="editing = !editing" class="text-xs text-blue-600 hover:text-blue-700 hover:underline" x-text="editing ? 'Cancel' : 'Edit'"></button>
                        </div>
                        <div x-show="!editing" class="mt-1 min-h-[4rem] w-full rounded border border-transparent px-3 py-2 text-sm text-gray-900 whitespace-pre-wrap">
                            @if($item->repro_steps)
                                <div class="text-left w-full">{!! preg_replace('/@(\w+)/', '<span class="text-blue-600 font-medium">@$1</span>', e($item->repro_steps)) !!}</div>
                            @else
                                <div class="text-left"><span class="text-gray-400 italic">No repro steps</span></div>
                            @endif
                        </div>
                        <textarea x-show="editing" x-cloak name="repro_steps" rows="4" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('repro_steps', $item->repro_steps) }}</textarea>
                    </div>
                @endif

                <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Save changes</button>
            </form>

            <div class="mt-8 border-t border-gray-200 pt-6">
                <h3 class="text-sm font-medium text-gray-900">Comments</h3>
                <ul class="mt-3 space-y-3">
                    @foreach($item->comments as $comment)
                        <li class="rounded-md bg-gray-50 p-3">
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
                                    <p class="text-sm text-gray-900">{{ $comment->body }}</p>
                                    <p class="mt-1 text-xs text-gray-500">{{ $commentUser->name }} · {{ $comment->created_at->diffForHumans() }}</p>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
                <form action="{{ route('items.comments.store', [$board, $item]) }}" method="POST" class="mt-4">
                    @csrf
                    <input type="hidden" name="view" value="{{ request('view', $board->view_type) }}" />
                    <textarea name="body" rows="3" placeholder="Add a comment..." required class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                    @error('body') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    <button type="submit" class="mt-2 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Add comment</button>
                </form>
            </div>
            @elseif($activeTab === 'history')
            <!-- History Tab -->
            <div class="space-y-3">
                @php
                    $historyItems = collect();
                    
                    // Add creation event
                    $historyItems->push([
                        'type' => 'created',
                        'user' => $item->creator,
                        'text' => 'created this ' . ($item->isBug() ? 'bug' : 'task'),
                        'timestamp' => $item->created_at,
                        'icon' => '+',
                        'iconBg' => 'bg-blue-100',
                        'iconText' => 'text-blue-700',
                    ]);
                    
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
                    
                    // Add assignee if exists
                    if ($item->assignee) {
                        $historyItems->push([
                            'type' => 'assigned',
                            'user' => null,
                            'text' => 'Assigned to ' . $item->assignee->name,
                            'timestamp' => $item->updated_at,
                            'icon' => '👤',
                            'iconBg' => 'bg-green-100',
                            'iconText' => 'text-green-700',
                        ]);
                    }
                    
                    // Sort by timestamp (oldest first)
                    $historyItems = $historyItems->sortBy('timestamp');
                @endphp

                @forelse($historyItems as $history)
                <div class="flex items-start gap-3 border-b border-gray-100 pb-3 last:border-0">
                    @if($history['user'])
                        @php
                            $historyUser = $history['user'];
                            $photoUrl = route('api.users.photo', $historyUser);
                            $nameParts = explode(' ', trim($historyUser->name));
                            $initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr($nameParts[count($nameParts) - 1], 0, 1) : ''));
                        @endphp
                        <div class="relative h-8 w-8 shrink-0 overflow-hidden rounded-full bg-gray-300">
                            <img src="{{ $photoUrl }}" alt="{{ $historyUser->name }}" class="h-full w-full object-cover" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
                            <div class="hidden h-full w-full items-center justify-center bg-gray-400 text-xs font-medium text-white">
                                {{ $initials }}
                            </div>
                        </div>
                    @else
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ $history['iconBg'] }} text-xs font-medium {{ $history['iconText'] }}">
                            {{ $history['icon'] }}
                        </div>
                    @endif
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-gray-900">
                            @if($history['user'])
                                <span class="font-medium">{{ $history['user']->name }}</span> {{ $history['text'] }}
                            @else
                                {{ $history['text'] }}
                            @endif
                        </p>
                        @if(isset($history['body']))
                            <p class="mt-1 text-sm text-gray-700">{{ $history['body'] }}</p>
                        @endif
                        <p class="mt-0.5 text-xs text-gray-500">{{ $history['timestamp']->format('M j, Y g:i A') }}</p>
                    </div>
                </div>
                @empty
                <div class="py-8 text-center">
                    <p class="text-sm text-gray-500">No history available</p>
                </div>
                @endforelse
            </div>
            @endif
        </div>
        @else
        <div class="flex-1 p-4 text-sm text-gray-500">Item not found.</div>
        @endif
    </div>
</div>
