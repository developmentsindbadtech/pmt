@extends('layouts.app')

@section('title', $sheet->name . ' - PMT')

@section('content')
    <div class="mx-auto max-w-7xl h-full px-4 sm:px-6 lg:px-8">
        <div class="mb-4">
            <a href="{{ route('sheets.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Sheets</a>
            @if ($sheet->description)
                <p class="mt-1 text-sm text-gray-500">{{ $sheet->description }}</p>
            @endif
        </div>

        @livewire('sheet-grid', ['sheetId' => $sheet->id])

        <div class="min-h-[min(160px,20vh)]" aria-hidden="true"></div>
    </div>
@endsection
