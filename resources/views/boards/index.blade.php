@extends('layouts.app')

@section('title', 'Boards - PMT')

@section('content')
    <div class="mx-auto h-full max-w-7xl overflow-y-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Boards</h1>
                <p class="mt-1 text-sm text-gray-500">
                    @if(! empty($isAdmin))
                        Team boards — manage who can access them from User Management.
                    @else
                        Boards an admin shared with you.
                    @endif
                    Pin and Hide change only your sidebar.
                </p>
            </div>
            <div class="flex items-center gap-2">
                @if(! empty($isAdmin))
                    <a href="{{ route('user-management.index') }}#boards" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Manage access</a>
                    <a href="{{ route('boards.create') }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">New Board</a>
                @endif
            </div>
        </div>

        @if ($boards->isEmpty())
            <div class="mt-12 rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
                <p class="text-gray-500">
                    @if(! empty($isAdmin))
                        No boards yet.
                    @else
                        No boards assigned to you yet. Ask an admin for access.
                    @endif
                </p>
                @if(! empty($isAdmin))
                    <a href="{{ route('boards.create') }}" class="mt-4 inline-block text-blue-600 hover:text-blue-700">
                        Create the first board
                    </a>
                @endif
            </div>
        @else
            <ul class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($boards as $board)
                    <li class="group relative rounded-xl border border-slate-200 bg-white shadow-sm {{ $board->nav_hidden ? 'bg-slate-100/80' : '' }}">
                        <a href="{{ route('boards.show', $board) }}" class="block p-4 hover:text-blue-700">
                            <h2 class="pr-24 text-base font-semibold tracking-tight text-slate-900">{{ $board->name }}</h2>
                            @if ($board->description)
                                <p class="mt-1 text-sm text-slate-500 line-clamp-2">{{ $board->description }}</p>
                            @endif
                            <p class="mt-2 text-xs text-slate-500">
                                {{ $board->items_count }} active {{ $board->items_count === 1 ? 'item' : 'items' }}
                                @if($board->nav_pinned)
                                    · Pinned
                                @endif
                                @if($board->nav_hidden)
                                    · Hidden from your sidebar
                                @endif
                            </p>
                        </a>
                        <div class="px-4 pb-4">
                            <a href="{{ route('boards.analytics', $board) }}" class="text-xs font-medium text-blue-700 hover:text-blue-800">Analytics</a>
                        </div>
                        <div class="absolute right-3 top-3 flex gap-2 {{ ($board->nav_pinned || $board->nav_hidden) ? '' : 'opacity-0 group-hover:opacity-100 focus-within:opacity-100' }}">
                            <form method="POST" action="{{ route('nav.prefs') }}">
                                @csrf
                                <input type="hidden" name="type" value="board" />
                                <input type="hidden" name="id" value="{{ $board->id }}" />
                                <input type="hidden" name="action" value="pin" />
                                <button type="submit" class="text-xs font-medium {{ $board->nav_pinned ? 'text-amber-700' : 'text-slate-500 hover:text-slate-900' }}">{{ $board->nav_pinned ? 'Unpin' : 'Pin' }}</button>
                            </form>
                            <form method="POST" action="{{ route('nav.prefs') }}">
                                @csrf
                                <input type="hidden" name="type" value="board" />
                                <input type="hidden" name="id" value="{{ $board->id }}" />
                                <input type="hidden" name="action" value="hide" />
                                <button type="submit" class="text-xs font-medium text-slate-500 hover:text-slate-900">{{ $board->nav_hidden ? 'Unhide' : 'Hide' }}</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
