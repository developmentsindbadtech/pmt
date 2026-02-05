@extends('layouts.app')

@section('title', 'Boards - PMT')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold text-gray-900">Boards</h1>
            @if(auth()->user()->is_admin)
            <a
                href="{{ route('boards.create') }}"
                class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
            >
                New Board
            </a>
            @endif
        </div>

        @if ($boards->isEmpty())
            <div class="mt-12 rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
                <p class="text-gray-500">No boards available yet.</p>
                @if(auth()->user()->is_admin)
                <a href="{{ route('boards.create') }}" class="mt-4 inline-block text-blue-600 hover:text-blue-700">
                    Create the first board
                </a>
                @endif
            </div>
        @else
            <ul class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($boards as $board)
                    <li>
                        <a
                            href="{{ route('boards.show', $board) }}"
                            class="block rounded-lg border border-gray-200 bg-white p-4 shadow-sm hover:border-blue-300 hover:shadow-md"
                        >
                            <h2 class="font-medium text-gray-900">{{ $board->name }}</h2>
                            @if ($board->description)
                                <p class="mt-1 text-sm text-gray-500 line-clamp-2">{{ $board->description }}</p>
                            @endif
                            <p class="mt-2 text-xs text-gray-400">{{ ucfirst($board->view_type) }} · {{ $board->items_count }} items</p>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
