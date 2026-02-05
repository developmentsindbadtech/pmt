@extends('layouts.app')

@section('title', $board->name . ' - PMT')

@section('content')
    <div class="mx-auto max-w-7xl h-full px-4 sm:px-6 lg:px-8">
        @livewire('board-content', [
            'boardId' => $board->id, 
            'view' => request('view', $board->view_type), 
            'filterAssigneeId' => $filterAssigneeId ?? null, 
            'filterUnassigned' => $filterUnassigned ?? false, 
            'filterType' => $filterType ?? null, 
            'selectedItemId' => $selectedItemId ?? null
        ])
    </div>
@endsection
