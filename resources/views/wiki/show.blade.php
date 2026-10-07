@extends('layouts.app')

@section('title', $page->title.' - Wiki - PMT')

@section('content')
    <div class="mx-auto h-full max-w-3xl overflow-y-auto px-4 sm:px-6 lg:px-8">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <a href="{{ route('wiki.index') }}" class="text-sm text-gray-500 hover:text-gray-800">&larr; Wiki</a>
            <div class="flex items-center gap-2">
                <a href="{{ route('wiki.edit', $page) }}" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Edit</a>
                @if($page->canDelete(auth()->user()))
                    <form action="{{ route('wiki.destroy', $page) }}" method="POST" onsubmit="return confirm('Delete this page?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="rounded-lg px-3 py-1.5 text-sm font-medium text-rose-600 hover:bg-rose-50">Delete</button>
                    </form>
                @endif
            </div>
        </div>

        @if (session('success'))
            <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">{{ session('success') }}</div>
        @endif

        <article class="rounded-xl border border-slate-200 bg-white px-5 py-6 shadow-sm sm:px-8">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $page->title }}</h1>
            <p class="mt-2 text-xs text-slate-500">
                Created by {{ $page->author?->name ?? 'Unknown' }}
                {{ $page->created_at?->timezone(config('app.timezone'))->format('M j, Y') }}
                <span class="text-slate-300">·</span>
                Last edited {{ $page->updated_at?->timezone(config('app.timezone'))->format('M j, Y g:i A') }}
                @if($page->editor)
                    by {{ $page->editor->name }}
                @endif
            </p>

            @if(trim((string) $page->body) === '')
                <p class="mt-8 text-sm text-slate-500">This page is empty. <a href="{{ route('wiki.edit', $page) }}" class="font-medium text-blue-700 hover:text-blue-800">Add notes</a>.</p>
            @else
                <div class="wiki-prose mt-6">
                    {!! $page->html() !!}
                </div>
            @endif
        </article>
    </div>
@endsection
