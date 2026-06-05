<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', $title ?? config('app.name'))</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
        <style>
            /* Accessibility: always show a clear focus ring for keyboard users. */
            :focus-visible {
                outline: 2px solid #3b82f6;
                outline-offset: 2px;
                border-radius: 4px;
            }
            /* Respect users who prefer reduced motion. */
            @media (prefers-reduced-motion: reduce) {
                *, *::before, *::after {
                    animation-duration: 0.001ms !important;
                    animation-iteration-count: 1 !important;
                    transition-duration: 0.001ms !important;
                    scroll-behavior: auto !important;
                }
            }
        </style>
    </head>
    <body class="min-h-screen bg-white text-gray-900 antialiased overflow-hidden">
        <div class="relative z-10" x-data="sidebar()">
        @auth
            {{-- Top bar (brand + user) --}}
            <nav class="sticky top-0 z-40 border-b border-gray-200 bg-white shadow-sm">
                <div class="flex h-14 items-center justify-between px-4 sm:px-6 lg:px-8">
                    <div class="flex items-center gap-3">
                        <button type="button" @click="expanded = !expanded" class="rounded p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700" title="{{ __('Toggle sidebar') }}" aria-label="Toggle sidebar">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        </button>
                        <a href="{{ route('boards.index') }}" class="text-xl font-semibold text-gray-800">Project Management Tool</a>
                    </div>
                    <div class="flex items-center gap-4">
                        {{-- User info: name, optional avatar, and Admin/User from Employee type (not status) --}}
                        <div class="flex items-center gap-2">
                            @php
                                $currentUser = auth()->user();
                                $photoUrl = route('api.users.photo', $currentUser);
                                $nameParts = explode(' ', trim($currentUser->name));
                                $initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr($nameParts[count($nameParts) - 1], 0, 1) : ''));
                            @endphp
                            <div class="relative h-8 w-8 shrink-0 overflow-hidden rounded-full bg-gray-300">
                                <img src="{{ $photoUrl }}" alt="{{ $currentUser->name }}" class="h-full w-full object-cover" loading="eager" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
                                <div class="hidden h-full w-full items-center justify-center bg-gray-400 text-xs font-medium text-white">
                                    {{ $initials }}
                                </div>
                            </div>
                            <span class="text-sm text-gray-700">{{ $currentUser->name }}</span>
                            <span class="rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">{{ $currentUser->is_admin ? 'Admin' : 'User' }}</span>
                        </div>
                        <form method="POST" action="{{ route('logout') }}" class="inline">
                            @csrf
                            <button type="submit" class="text-sm text-gray-600 hover:text-gray-900">Logout</button>
                        </form>
                    </div>
                </div>
            </nav>

            <div class="flex">
                {{-- Left sidebar: expand/collapse --}}
                <aside
                    class="fixed left-0 top-14 z-30 flex h-[calc(100vh-3.5rem)] flex-col border-r border-gray-200 bg-gray-800 text-gray-200 transition-[width] duration-200 ease-in-out"
                    :class="expanded ? 'w-56' : 'w-14'"
                    style="padding-top: 0;"
                >
                    <div class="flex flex-1 flex-col overflow-hidden py-3">
                        <div class="shrink-0 space-y-0.5 px-2">
                            <a href="{{ route('boards.index') }}"
                               class="flex items-center gap-3 rounded-lg px-3 py-2 transition-colors {{ request()->routeIs('boards.index') ? 'bg-gray-700 text-white' : 'text-gray-300 hover:bg-gray-700/70 hover:text-white' }}"
                               :class="!expanded && 'justify-center'" title="Boards">
                                <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/></svg>
                                <span x-show="expanded" class="truncate text-sm font-medium">Boards</span>
                            </a>
                            <a href="{{ route('sheets.index') }}"
                               class="flex items-center gap-3 rounded-lg px-3 py-2 transition-colors {{ request()->routeIs('sheets.*') ? 'bg-gray-700 text-white' : 'text-gray-300 hover:bg-gray-700/70 hover:text-white' }}"
                               :class="!expanded && 'justify-center'" title="Sheets">
                                <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18M12 4v16M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/></svg>
                                <span x-show="expanded" class="truncate text-sm font-medium">Sheets</span>
                            </a>
                            @if(auth()->user()->is_admin)
                            <a href="{{ route('user-management.index') }}"
                               class="flex items-center gap-3 rounded-lg px-3 py-2 transition-colors {{ request()->routeIs('user-management.*') ? 'bg-gray-700 text-white' : 'text-gray-300 hover:bg-gray-700/70 hover:text-white' }}"
                               :class="!expanded && 'justify-center'" title="User Management">
                                <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                                <span x-show="expanded" class="truncate text-sm">User Management</span>
                            </a>
                            @endif
                        </div>

                        <div class="mt-2 flex-1 overflow-y-auto border-t border-gray-700/70 pt-2" x-show="expanded">
                            {{-- Boards group --}}
                            <div class="mb-1 flex items-center justify-between px-3">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Boards</p>
                                @if(auth()->user()->is_admin)
                                    <a href="{{ route('boards.create') }}" class="rounded p-0.5 text-gray-500 hover:bg-gray-700 hover:text-white" title="New board">
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m7-7H5"/></svg>
                                    </a>
                                @endif
                            </div>
                            @forelse ($sidebarBoards ?? [] as $board)
                                <a href="{{ route('boards.show', $board) }}"
                                   title="{{ $board->name }}"
                                   class="mx-2 flex items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors {{ (isset($currentBoardId) && $currentBoardId === $board->id) ? 'bg-gray-700 text-white' : 'text-gray-300 hover:bg-gray-700/70 hover:text-white' }}">
                                    <svg class="h-4 w-4 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                    <span class="truncate">{{ $board->name }}</span>
                                </a>
                            @empty
                                <p class="px-3 py-1 text-xs text-gray-500">No boards yet.</p>
                            @endforelse

                            {{-- Sheets group --}}
                            <div class="mb-1 mt-4 flex items-center justify-between px-3">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Sheets</p>
                                <a href="{{ route('sheets.create') }}" class="rounded p-0.5 text-gray-500 hover:bg-gray-700 hover:text-white" title="New sheet">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m7-7H5"/></svg>
                                </a>
                            </div>
                            @forelse ($sidebarSheets ?? [] as $sheet)
                                <a href="{{ route('sheets.show', $sheet) }}"
                                   title="{{ $sheet->name }}"
                                   class="mx-2 flex items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors {{ (isset($currentSheetId) && $currentSheetId === $sheet->id) ? 'bg-gray-700 text-white' : 'text-gray-300 hover:bg-gray-700/70 hover:text-white' }}">
                                    <svg class="h-4 w-4 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18M12 4v16M4 4h16a1 1 0 011 1v14a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/></svg>
                                    <span class="truncate">{{ $sheet->name }}</span>
                                </a>
                            @empty
                                <p class="px-3 py-1 text-xs text-gray-500">No sheets yet.</p>
                            @endforelse
                        </div>
                    </div>
                    <div class="border-t border-gray-700 p-2">
                        <button type="button" @click="expanded = !expanded" class="flex w-full items-center justify-center gap-2 rounded p-2 text-gray-400 hover:bg-gray-700 hover:text-white" :class="expanded ? 'justify-start px-3' : ''" title="Collapse sidebar">
                            <svg class="h-5 w-5 shrink-0 transition-transform" :class="expanded && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 7l-7-7 7-7"/></svg>
                            <span x-show="expanded" class="text-sm">Collapse</span>
                        </button>
                    </div>
                </aside>

                {{-- Main content: offset by sidebar width --}}
                <main class="h-[calc(100vh-3.5rem)] min-w-0 flex-1 overflow-hidden py-6 transition-[margin-left] duration-200 ease-in-out" :style="{ marginLeft: expanded ? '14rem' : '3.5rem' }">
                    @hasSection('content')
                        @yield('content')
                    @else
                        {{ $slot ?? '' }}
                    @endif
                </main>
            </div>
        @else
            <main class="py-6">
                @hasSection('content')
                    @yield('content')
                @else
                    {{ $slot ?? '' }}
                @endif
            </main>
        @endauth

        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('sidebar', () => ({
                    expanded: (() => { try { return localStorage.getItem('pmt-sidebar-expanded') !== 'false'; } catch (e) { return true; } })(),
                    init() {
                        this.$watch('expanded', v => { try { localStorage.setItem('pmt-sidebar-expanded', v); } catch (e) {} });
                    }
                }));
            });
        </script>
        @livewireScripts
        </div>
    </body>
</html>
