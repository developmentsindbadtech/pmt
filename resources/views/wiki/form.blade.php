@extends('layouts.app')

@section('title', ($mode === 'create' ? 'New page' : 'Edit '.$page->title).' - Wiki - PMT')

@section('content')
    <div class="mx-auto h-full max-w-3xl overflow-y-auto px-4 sm:px-6 lg:px-8">
        <a href="{{ $mode === 'edit' ? route('wiki.show', $page) : route('wiki.index') }}" class="text-sm text-gray-500 hover:text-gray-800">&larr; {{ $mode === 'edit' ? 'Back to page' : 'Wiki' }}</a>
        <h1 class="mt-3 text-2xl font-semibold tracking-tight text-gray-900">{{ $mode === 'create' ? 'New page' : 'Edit page' }}</h1>
        <p class="mt-1 text-sm text-slate-500">Plain text with light Markdown: <code class="rounded bg-slate-100 px-1 text-xs"># heading</code>, <code class="rounded bg-slate-100 px-1 text-xs">- list</code>, <code class="rounded bg-slate-100 px-1 text-xs">**bold**</code>.</p>

        <form
            action="{{ $mode === 'create' ? route('wiki.store') : route('wiki.update', $page) }}"
            method="POST"
            class="mt-6 space-y-4"
        >
            @csrf
            @if($mode === 'edit')
                @method('PUT')
            @endif
            <div>
                <label for="title" class="block text-sm font-medium text-gray-700">Title</label>
                <input
                    type="text"
                    name="title"
                    id="title"
                    value="{{ old('title', $page->title) }}"
                    required
                    maxlength="120"
                    class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
                @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="body" class="block text-sm font-medium text-gray-700">Content</label>
                <textarea
                    name="body"
                    id="body"
                    rows="16"
                    maxlength="50000"
                    class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm leading-relaxed text-slate-800 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    placeholder="# How we do this&#10;&#10;Write the steps the team should follow."
                >{{ old('body', $page->body) }}</textarea>
                @error('body') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex gap-3">
                <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">{{ $mode === 'create' ? 'Publish' : 'Save' }}</button>
                <a href="{{ $mode === 'edit' ? route('wiki.show', $page) : route('wiki.index') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
            </div>
        </form>
    </div>
@endsection
