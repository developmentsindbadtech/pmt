@extends('layouts.app')

@section('title', 'Sheets - PMT')

@section('content')
    <div class="mx-auto h-full max-w-7xl overflow-y-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Sheets</h1>
                <p class="mt-1 text-sm text-slate-500">Your sheets — create your own, or ones an admin shared with you. Pin and Hide change only your sidebar.</p>
            </div>
            <div class="flex items-center gap-2">
                @if(! empty($isAdmin))
                    <a href="{{ route('user-management.index') }}#sheets" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Manage access</a>
                @endif
                <a href="{{ route('sheets.create') }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">New Sheet</a>
            </div>
        </div>

        @if (session('success'))
            <div class="mt-4 rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">{{ session('success') }}</div>
        @endif

        @if ($sheets->isEmpty())
            <div class="mt-12 rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
                <p class="text-gray-500">No sheets yet.</p>
                <a href="{{ route('sheets.create') }}" class="mt-4 inline-block text-blue-600 hover:text-blue-700">Create your first sheet</a>
            </div>
        @else
            <ul class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($sheets as $sheet)
                    @php
                        $isOwner = (int) $sheet->created_by === (int) auth()->id();
                        $canDelete = $isOwner || auth()->user()?->is_admin;
                    @endphp
                    <li class="group relative rounded-xl border border-slate-200 bg-white shadow-sm {{ $sheet->nav_hidden ? 'bg-slate-100/80' : '' }}" @if($canDelete) x-data="{ menu: false }" @endif>
                        <a href="{{ route('sheets.show', $sheet) }}" class="block p-4 hover:text-blue-700">
                            <div class="flex items-start justify-between gap-2 pr-28">
                                <h2 class="text-base font-semibold tracking-tight text-slate-900">{{ $sheet->name }}</h2>
                                @if($isOwner)
                                    <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-slate-600">Owner</span>
                                @endif
                            </div>
                            @if ($sheet->description)
                                <p class="mt-1 text-sm text-slate-500 line-clamp-2">{{ $sheet->description }}</p>
                            @endif
                            <p class="mt-2 text-xs text-slate-500">
                                {{ $sheet->columns_count }} columns · {{ $sheet->rows_count }} rows
                                @if($sheet->nav_pinned)
                                    · Pinned
                                @endif
                                @if($sheet->nav_hidden)
                                    · Hidden from your sidebar
                                @endif
                                @if(! $isOwner && $sheet->creator)
                                    · {{ $sheet->creator->name }}
                                @endif
                            </p>
                        </a>
                        <div class="px-4 pb-4">
                            <a href="{{ route('sheets.analytics', $sheet) }}" class="text-xs font-medium text-blue-700 hover:text-blue-800">Analytics</a>
                        </div>
                        <div class="absolute right-3 top-3 flex items-center gap-2 {{ ($sheet->nav_pinned || $sheet->nav_hidden || $canDelete) ? '' : 'opacity-0 group-hover:opacity-100 focus-within:opacity-100' }}">
                            <div class="flex gap-2 {{ ($sheet->nav_pinned || $sheet->nav_hidden) ? '' : 'opacity-0 group-hover:opacity-100 focus-within:opacity-100' }}">
                                <form method="POST" action="{{ route('nav.prefs') }}">
                                    @csrf
                                    <input type="hidden" name="type" value="sheet" />
                                    <input type="hidden" name="id" value="{{ $sheet->id }}" />
                                    <input type="hidden" name="action" value="pin" />
                                    <button type="submit" class="text-xs font-medium {{ $sheet->nav_pinned ? 'text-amber-700' : 'text-slate-500 hover:text-slate-900' }}">{{ $sheet->nav_pinned ? 'Unpin' : 'Pin' }}</button>
                                </form>
                                <form method="POST" action="{{ route('nav.prefs') }}">
                                    @csrf
                                    <input type="hidden" name="type" value="sheet" />
                                    <input type="hidden" name="id" value="{{ $sheet->id }}" />
                                    <input type="hidden" name="action" value="hide" />
                                    <button type="submit" class="text-xs font-medium text-slate-500 hover:text-slate-900">{{ $sheet->nav_hidden ? 'Unhide' : 'Hide' }}</button>
                                </form>
                            </div>
                        @if($canDelete)
                            <div class="relative">
                                <button
                                    type="button"
                                    @click.stop="menu = !menu"
                                    class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                                    title="More"
                                    aria-label="More actions"
                                >⋯</button>
                                <div
                                    x-show="menu"
                                    @click.outside="menu = false"
                                    x-cloak
                                    class="absolute right-0 z-20 mt-1 w-36 rounded-md border border-gray-200 bg-white py-1 shadow-lg"
                                >
                                    <form action="{{ route('sheets.destroy', $sheet) }}" method="POST" onsubmit="return confirm('Delete this sheet and all its data?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="block w-full px-3 py-1.5 text-left text-sm text-red-600 hover:bg-red-50">Delete sheet</button>
                                    </form>
                                </div>
                            </div>
                        @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
