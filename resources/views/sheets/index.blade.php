@extends('layouts.app')

@section('title', 'Sheets - PMT')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Sheets</h1>
                <p class="mt-1 text-sm text-gray-500">Flexible data grids — add your own columns and just type the details.</p>
            </div>
            <a href="{{ route('sheets.create') }}" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">New Sheet</a>
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
            <ul class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($sheets as $sheet)
                    <li class="relative">
                        <a href="{{ route('sheets.show', $sheet) }}" class="block rounded-lg border border-gray-200 bg-white p-4 shadow-sm hover:border-blue-300 hover:shadow-md">
                            <h2 class="font-medium text-gray-900">{{ $sheet->name }}</h2>
                            @if ($sheet->description)
                                <p class="mt-1 text-sm text-gray-500 line-clamp-2">{{ $sheet->description }}</p>
                            @endif
                            <p class="mt-2 text-xs text-gray-400">{{ $sheet->columns_count }} columns · {{ $sheet->rows_count }} rows</p>
                        </a>
                        <form action="{{ route('sheets.destroy', $sheet) }}" method="POST" class="absolute right-3 top-3" onsubmit="return confirm('Delete this sheet and all its data?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-gray-400 hover:text-red-600">Delete</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
