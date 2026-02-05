@extends('layouts.app')

@section('title', 'User Management - PMT')

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 py-4">
        <h1 class="text-xl font-semibold text-gray-900 mb-4">User Management</h1>

        @if (session('success'))
            <div class="mb-3 rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

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
                            <div class="flex flex-wrap gap-x-4 gap-y-2">
                                @foreach ($users as $user)
                                    <label class="flex items-center gap-1.5 cursor-pointer">
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
                            @if ($users->isEmpty())
                                <p class="text-xs text-gray-500">No users available.</p>
                            @endif
                            <div class="mt-3 flex justify-end">
                                <button
                                    type="submit"
                                    class="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700"
                                >
                                    Save
                                </button>
                            </div>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endsection
