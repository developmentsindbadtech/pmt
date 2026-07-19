@extends('layouts.app')

@section('title', $sheet->name . ' - PMT')

@section('content')
    <div class="mx-auto h-full max-w-[1400px] overflow-y-auto px-4 py-4 sm:px-6 lg:px-8">
        <div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1">
            <a href="{{ route('sheets.index') }}" class="text-sm text-gray-500 hover:text-gray-800">&larr; Sheets</a>
            @if ($sheet->description)
                <span class="max-w-xl truncate text-sm text-gray-400" title="{{ $sheet->description }}">{{ $sheet->description }}</span>
            @endif
        </div>

        @if (session('success'))
            <div
                x-data="{ show: true }"
                x-show="show"
                x-init="setTimeout(() => show = false, 4000)"
                class="mb-3 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800"
            >{{ session('success') }}</div>
        @endif

        @livewire('sheet-grid', ['sheetId' => $sheet->id])
    </div>
@endsection
