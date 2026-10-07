@extends('layouts.app')

@section('title', 'Wiki - PMT')

@section('content')
    <div class="mx-auto h-full max-w-4xl overflow-y-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Wiki</h1>
                <p class="mt-1 text-sm text-slate-500">Shared notes for the team. Anyone can add or edit a page. Author and last editor stay on the page.</p>
            </div>
            <a href="{{ route('wiki.create') }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">New page</a>
        </div>

        @if (session('success'))
            <div class="mt-4 rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">{{ session('success') }}</div>
        @endif

        <form method="GET" action="{{ route('wiki.index') }}" class="mt-6">
            <label for="wiki-search" class="sr-only">Search wiki</label>
            <input
                id="wiki-search"
                type="search"
                name="q"
                value="{{ $term }}"
                placeholder="Search pages"
                class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder:text-slate-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
        </form>

        @if ($pages->isEmpty())
            <div class="mt-12 rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
                <p class="text-gray-500">{{ $term !== '' ? 'No pages match that search.' : 'No pages yet.' }}</p>
                @if ($term === '')
                    <a href="{{ route('wiki.create') }}" class="mt-4 inline-block text-blue-600 hover:text-blue-700">Write the first page</a>
                @endif
            </div>
        @else
            <ul class="mt-4 divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                @foreach ($pages as $page)
                    <li>
                        <a href="{{ route('wiki.show', $page) }}" class="flex items-start justify-between gap-4 px-4 py-3 hover:bg-slate-50">
                            <div class="min-w-0">
                                <h2 class="truncate text-sm font-semibold text-slate-900">{{ $page->title }}</h2>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ $page->author?->name ?? 'Unknown' }}
                                    <span class="text-slate-300">·</span>
                                    Edited {{ $page->updated_at?->timezone(config('app.timezone'))->format('M j, Y') }}
                                    @if($page->editor && (int) $page->updated_by !== (int) $page->created_by)
                                        by {{ $page->editor->name }}
                                    @endif
                                </p>
                            </div>
                            <span class="shrink-0 pt-0.5 text-xs text-slate-400">{{ $page->updated_at?->diffForHumans() }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
