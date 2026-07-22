@extends('layouts.app')

@section('title', 'User Management - PMT')

@section('content')
    @php
        $nextBoardSort = $boardSort === 'asc' ? 'desc' : 'asc';
        $nextSheetSort = $sheetSort === 'asc' ? 'desc' : 'asc';
        $initialTab = request()->filled('sheet_sort') && ! request()->filled('board_sort') ? 'sheets' : 'boards';
    @endphp

    <div
        class="h-full overflow-y-auto"
        x-data="{
            tab: '{{ $initialTab }}',
            q: '',
            match(...parts) {
                const needle = this.q.trim().toLowerCase();
                if (!needle) return true;
                return parts.some((p) => (p || '').toLowerCase().includes(needle));
            }
        }"
        x-init="
            if (window.location.hash === '#sheets') tab = 'sheets';
            if (window.location.hash === '#boards') tab = 'boards';
        "
    >
        <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
            {{-- Header --}}
            <div class="mb-6">
                <h1 class="text-xl font-semibold tracking-tight text-gray-900">User Management</h1>
                <p class="mt-1 text-sm text-gray-500">Boards: who sees each board. Sheets: tick people (including admins) so the sheet appears in their personal Sheets list — the owner always keeps access.</p>
            </div>

            @if (session('success'))
                <div class="mb-4 flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 ring-1 ring-emerald-200/70">
                    <svg class="h-4 w-4 shrink-0 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    {{ session('success') }}
                </div>
            @endif

            @if ($users->isEmpty())
                <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    No non-admin users yet. Locally: log out → <span class="font-medium">Continue as User</span> once, then come back here to assign them.
                </div>
            @endif

                {{-- Tabs + search --}}
                <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="inline-flex rounded-lg bg-gray-100 p-0.5" role="tablist">
                        <button
                            type="button"
                            role="tab"
                            @click="tab = 'boards'; window.location.hash = 'boards'"
                            :class="tab === 'boards' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                            class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                        >
                            Boards
                            <span class="ml-1 text-gray-400">{{ $boards->count() }}</span>
                        </button>
                        <button
                            type="button"
                            role="tab"
                            @click="tab = 'sheets'; window.location.hash = 'sheets'"
                            :class="tab === 'sheets' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                            class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                        >
                            Sheets
                            <span class="ml-1 text-gray-400">{{ $sheets->count() }}</span>
                        </button>
                    </div>

                    <label class="relative block w-full sm:w-64">
                        <span class="sr-only">Search</span>
                        <svg class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/>
                        </svg>
                        <input
                            type="search"
                            x-model="q"
                            placeholder="Search sheet, owner, or board…"
                            class="w-full rounded-lg border border-gray-200 bg-white py-2 pl-8 pr-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
                        />
                    </label>
                </div>

                {{-- ===================== BOARDS ===================== --}}
                <section x-show="tab === 'boards'" x-cloak id="boards">
                    <div class="mb-3 flex items-center justify-between">
                        <p class="text-xs text-gray-500">Check users who should see each board in their sidebar.</p>
                        <a
                            href="{{ route('user-management.index', ['board_sort' => $nextBoardSort, 'sheet_sort' => $sheetSort]) }}#boards"
                            class="text-xs font-medium text-gray-500 hover:text-gray-800"
                        >
                            Name {{ $boardSort === 'asc' ? 'A–Z' : 'Z–A' }}
                        </a>
                    </div>

                    @if ($boards->isEmpty())
                        <div class="rounded-lg border border-dashed border-gray-200 bg-gray-50 px-4 py-10 text-center text-sm text-gray-500">
                            No boards yet.
                        </div>
                    @else
                        <div class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white">
                            @foreach ($boards as $board)
                                <div
                                    class="p-4"
                                    x-show="match(@js($board->name))"
                                    x-data="{ dirty: false }"
                                >
                                    <form
                                        action="{{ route('user-management.update', $board) }}"
                                        method="POST"
                                        @change="dirty = true"
                                    >
                                        @csrf
                                        @method('PUT')

                                        <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <h3 class="truncate text-sm font-medium text-gray-900">{{ $board->name }}</h3>
                                                @if ($board->description)
                                                    <p class="mt-0.5 line-clamp-1 text-xs text-gray-500">{{ $board->description }}</p>
                                                @endif
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                                                    {{ $board->users_count }} assigned
                                                </span>
                                                <button
                                                    type="button"
                                                    class="text-xs text-gray-500 hover:text-gray-800"
                                                    @click="$el.closest('form').querySelectorAll('input[type=checkbox]').forEach(c => c.checked = true); dirty = true"
                                                >All</button>
                                                <button
                                                    type="button"
                                                    class="text-xs text-gray-500 hover:text-gray-800"
                                                    @click="$el.closest('form').querySelectorAll('input[type=checkbox]').forEach(c => c.checked = false); dirty = true"
                                                >None</button>
                                                <button
                                                    type="submit"
                                                    class="rounded-md px-2.5 py-1 text-xs font-medium transition"
                                                    :class="dirty
                                                        ? 'bg-blue-600 text-white hover:bg-blue-700'
                                                        : 'bg-gray-100 text-gray-400 cursor-default'"
                                                    :disabled="!dirty"
                                                >Save</button>
                                            </div>
                                        </div>

                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach ($users as $user)
                                                <label
                                                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition has-[:checked]:border-blue-200 has-[:checked]:bg-blue-50 has-[:checked]:text-blue-800 border-gray-200 bg-white text-gray-600 hover:border-gray-300"
                                                    title="{{ $user->email }}"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        name="user_ids[]"
                                                        value="{{ $user->id }}"
                                                        {{ $board->users->contains($user->id) ? 'checked' : '' }}
                                                        class="h-3.5 w-3.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                                    />
                                                    <span>{{ $user->name }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-3 text-center text-xs text-gray-400" x-show="q.trim()" x-cloak>
                            Showing matches for “<span x-text="q.trim()"></span>”
                        </p>
                    @endif
                </section>

                {{-- ===================== SHEETS ===================== --}}
                <section x-show="tab === 'sheets'" x-cloak id="sheets">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs text-gray-500">Search a sheet or owner, then tick who should see it in Sheets (admins included). Owner is locked in.</p>
                        <a
                            href="{{ route('user-management.index', ['board_sort' => $boardSort, 'sheet_sort' => $nextSheetSort]) }}#sheets"
                            class="text-xs font-medium text-gray-500 hover:text-gray-800"
                        >
                            Name {{ $sheetSort === 'asc' ? 'A–Z' : 'Z–A' }}
                        </a>
                    </div>

                    @if ($sheets->isEmpty())
                        <div class="rounded-lg border border-dashed border-gray-200 bg-gray-50 px-4 py-10 text-center text-sm text-gray-500">
                            No sheets yet. People create their own from Sheets → New Sheet.
                        </div>
                    @else
                        <div class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white">
                            @foreach ($sheets as $sheet)
                                @php
                                    $owner = $sheet->creator;
                                    $ownerId = (int) ($sheet->created_by ?? 0);
                                    $extraCount = $sheet->users->where('id', '!=', $ownerId)->count();
                                @endphp
                                <div
                                    class="p-4"
                                    x-show="match(@js($sheet->name), @js($owner?->name ?? ''), @js($owner?->email ?? ''))"
                                    x-data="{ dirty: false, open: {{ $extraCount > 0 || request()->query('sheet') == $sheet->id ? 'true' : 'false' }} }"
                                >
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <a href="{{ route('sheets.show', $sheet) }}" class="truncate text-sm font-medium text-gray-900 hover:text-blue-700">{{ $sheet->name }}</a>
                                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-slate-600">
                                                    {{ $owner?->name ?? 'No owner' }}
                                                </span>
                                            </div>
                                            @if ($sheet->description)
                                                <p class="mt-0.5 line-clamp-1 text-xs text-gray-500">{{ $sheet->description }}</p>
                                            @endif
                                            <p class="mt-1 text-xs text-gray-400">
                                                Owner always has access · {{ $extraCount }} other{{ $extraCount === 1 ? '' : 's' }}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            class="rounded-md border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50"
                                            @click="open = !open"
                                            x-text="open ? 'Close' : 'Edit access'"
                                        ></button>
                                    </div>

                                    <form
                                        x-show="open"
                                        x-cloak
                                        action="{{ route('user-management.update-sheet', $sheet) }}"
                                        method="POST"
                                        class="mt-3 border-t border-gray-100 pt-3"
                                        @change="dirty = true"
                                    >
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="board_sort" value="{{ $boardSort }}" />
                                        <input type="hidden" name="sheet_sort" value="{{ $sheetSort }}" />

                                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                            <p class="text-xs text-gray-500">Tick people (including yourself as admin) so the sheet shows in their Sheets list, then Save.</p>
                                            <button
                                                type="submit"
                                                class="rounded-md px-2.5 py-1 text-xs font-medium transition"
                                                :class="dirty
                                                    ? 'bg-blue-600 text-white hover:bg-blue-700'
                                                    : 'bg-gray-100 text-gray-400 cursor-default'"
                                                :disabled="!dirty"
                                            >Save</button>
                                        </div>

                                        <div class="flex flex-wrap gap-1.5">
                                            @if($owner)
                                                <span
                                                    class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs text-slate-700"
                                                    title="Owner — always has access"
                                                >
                                                    <span class="font-medium">{{ $owner->name }}</span>
                                                    <span class="text-[10px] uppercase tracking-wide text-slate-400">Owner</span>
                                                </span>
                                                <input type="hidden" name="user_ids[]" value="{{ $owner->id }}" />
                                            @endif

                                            @forelse ($sheetUsers as $user)
                                                @if((int) $user->id === $ownerId)
                                                    @continue
                                                @endif
                                                <label
                                                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition has-[:checked]:border-blue-200 has-[:checked]:bg-blue-50 has-[:checked]:text-blue-800 border-gray-200 bg-white text-gray-600 hover:border-gray-300"
                                                    title="{{ $user->email }}{{ $user->is_admin ? ' (admin)' : '' }}"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        name="user_ids[]"
                                                        value="{{ $user->id }}"
                                                        {{ $sheet->users->contains($user->id) ? 'checked' : '' }}
                                                        class="h-3.5 w-3.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                                    />
                                                    <span>{{ $user->name }}</span>
                                                    @if($user->is_admin)
                                                        <span class="text-[10px] uppercase tracking-wide text-slate-400">Admin</span>
                                                    @endif
                                                </label>
                                            @empty
                                                <p class="text-xs text-gray-400">No other users to add yet.</p>
                                            @endforelse
                                        </div>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-3 text-center text-xs text-gray-400" x-show="q.trim()" x-cloak>
                            Showing matches for “<span x-text="q.trim()"></span>”
                        </p>
                    @endif
                </section>
        </div>
    </div>
@endsection
