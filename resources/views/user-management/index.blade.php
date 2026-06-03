@extends('layouts.app')

@section('title', 'User Management - PMT')

@section('content')
    <div class="h-full overflow-y-auto">
        <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 py-4">
            <h1 class="text-xl font-semibold text-gray-900 mb-4">User Management</h1>

            @if (session('success'))
                <div class="mb-3 rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
                    {{ session('success') }}
                </div>
            @endif

            @php
                $nextBoardSort = $boardSort === 'asc' ? 'desc' : 'asc';
                $nextSheetSort = $sheetSort === 'asc' ? 'desc' : 'asc';
            @endphp

            {{-- ===================== BOARDS ===================== --}}
            <div class="mb-8">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Boards (Projects)</h2>
                    <a href="{{ route('user-management.index', ['board_sort' => $nextBoardSort, 'sheet_sort' => $sheetSort]) }}#boards"
                       class="inline-flex items-center gap-1 rounded border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                        Sort by name {{ $boardSort === 'asc' ? '↑ (A–Z)' : '↓ (Z–A)' }}
                    </a>
                </div>
                <div id="boards"></div>

                @if ($boards->isEmpty())
                    <div class="rounded border border-gray-200 bg-gray-50 p-8 text-center text-sm text-gray-500">
                        No boards available yet.
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach ($boards as $board)
                            <div class="rounded border border-gray-200 bg-white">
                                <div class="border-b border-gray-100 px-4 py-2">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <span class="text-sm font-medium text-gray-900">{{ $board->name }}</span>
                                            @if ($board->description)
                                                <span class="ml-2 text-xs text-gray-500">· {{ $board->description }}</span>
                                            @endif
                                        </div>
                                        <span class="text-xs text-gray-400">{{ $board->users_count }} assigned</span>
                                    </div>
                                </div>
                                <form action="{{ route('user-management.update', $board) }}" method="POST" class="p-4">
                                    @csrf
                                    @method('PUT')
                                    <div class="overflow-x-auto">
                                        <div class="flex gap-x-4 gap-y-2 min-w-max pb-2">
                                            @foreach ($users as $user)
                                                <label class="flex items-center gap-1.5 cursor-pointer whitespace-nowrap shrink-0">
                                                    <input
                                                        type="checkbox"
                                                        name="user_ids[]"
                                                        value="{{ $user->id }}"
                                                        {{ $board->users->contains($user->id) ? 'checked' : '' }}
                                                        class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                                    />
                                                    <span class="text-sm text-gray-700">{{ $user->name }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                    @if ($users->isEmpty())
                                        <p class="text-xs text-gray-500">No users available.</p>
                                    @endif
                                    <div class="mt-3 flex justify-end">
                                        <button type="submit" class="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">
                                            Save
                                        </button>
                                    </div>
                                </form>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- ===================== SHEETS ===================== --}}
            <div class="mb-8">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Sheets</h2>
                    <a href="{{ route('user-management.index', ['board_sort' => $boardSort, 'sheet_sort' => $nextSheetSort]) }}#sheets"
                       class="inline-flex items-center gap-1 rounded border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                        Sort by name {{ $sheetSort === 'asc' ? '↑ (A–Z)' : '↓ (Z–A)' }}
                    </a>
                </div>
                <div id="sheets"></div>

                @if ($sheets->isEmpty())
                    <div class="rounded border border-gray-200 bg-gray-50 p-8 text-center text-sm text-gray-500">
                        No sheets available yet.
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach ($sheets as $sheet)
                            <div class="rounded border border-gray-200 bg-white">
                                <div class="border-b border-gray-100 px-4 py-2">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <span class="text-sm font-medium text-gray-900">{{ $sheet->name }}</span>
                                            @if ($sheet->description)
                                                <span class="ml-2 text-xs text-gray-500">· {{ $sheet->description }}</span>
                                            @endif
                                        </div>
                                        <span class="text-xs text-gray-400">{{ $sheet->users_count }} assigned</span>
                                    </div>
                                </div>
                                <form action="{{ route('user-management.update-sheet', $sheet) }}" method="POST" class="p-4">
                                    @csrf
                                    @method('PUT')
                                    <div class="overflow-x-auto">
                                        <div class="flex gap-x-4 gap-y-2 min-w-max pb-2">
                                            @foreach ($users as $user)
                                                <label class="flex items-center gap-1.5 cursor-pointer whitespace-nowrap shrink-0">
                                                    <input
                                                        type="checkbox"
                                                        name="user_ids[]"
                                                        value="{{ $user->id }}"
                                                        {{ $sheet->users->contains($user->id) ? 'checked' : '' }}
                                                        class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                                    />
                                                    <span class="text-sm text-gray-700">{{ $user->name }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                    @if ($users->isEmpty())
                                        <p class="text-xs text-gray-500">No users available.</p>
                                    @endif
                                    <div class="mt-3 flex justify-end">
                                        <button type="submit" class="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">
                                            Save
                                        </button>
                                    </div>
                                </form>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
