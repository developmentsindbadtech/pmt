<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Column;
use App\Models\Group;
use App\Models\UserBoardFilter;
use App\Services\MentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BoardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        
        $query = Board::query()->withCount('items');
        
        // Admins see all boards, regular users only see assigned boards
        if (! $user->is_admin) {
            $query->whereHas('users', function ($q) use ($user) {
                $q->where('users.id', $user->id);
            });
        }
        
        $boards = $query->orderBy('name', 'asc')
            ->limit(50)
            ->get();

        return view('boards.index', ['boards' => $boards]);
    }

    public function create(Request $request): View
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only administrators can create boards.');
        }
        return view('boards.create');
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only administrators can create boards.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        // Default to kanban view type
        $viewType = 'kanban';

        $board = Board::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'view_type' => $viewType,
            'created_by' => $request->user()->id,
        ]);

        // Always create kanban groups since default is kanban
        if ($viewType === 'kanban') {
            $defaultGroups = ['New', 'In Progress', 'Ready for Stage', 'Ready for QA', 'Rejected by QA', 'Closed'];
            foreach ($defaultGroups as $i => $name) {
                Group::create([
                    'board_id' => $board->id,
                    'name' => $name,
                    'position' => $i,
                ]);
            }
            Column::create([
                'board_id' => $board->id,
                'name' => 'Status',
                'type' => 'status',
                'position' => 0,
                'settings' => ['options' => $defaultGroups],
            ]);
        }

        Column::create([
            'board_id' => $board->id,
            'name' => 'Name',
            'type' => 'text',
            'position' => $viewType === 'kanban' ? 1 : 0,
        ]);

        return redirect()->route('boards.show', $board)->with('success', 'Board created.');
    }

    public function show(Request $request, Board $board): View|RedirectResponse
    {
        $user = $request->user();

        // Load users relationship first to avoid N+1 query
        $board->load('users');

        // Admins can access all boards, regular users only assigned boards
        if (! $user->is_admin && ! $board->users->contains($user->id)) {
            abort(403, 'You do not have access to this board.');
        }

        // Check if item is specified via query parameter (backward compatibility)
        $itemParam = $request->input('item');
        if ($itemParam) {
            // Redirect to clean URL format
            $item = \App\Models\Item::where('board_id', $board->id)
                ->where(function ($q) use ($itemParam) {
                    $q->where('id', $itemParam)->orWhere('number', $itemParam);
                })
                ->first();
            
            if ($item) {
                $view = $request->input('view', 'kanban');
                return redirect()->route('boards.show.item', [
                    'board' => $board->id,
                    'item' => $item->number,
                    'view' => $view
                ], 301);
            }
        }

        // Eager load all relationships in one query
        $board->load([
            'columns' => fn ($q) => $q->orderBy('position'),
            'groups' => fn ($q) => $q->orderBy('position'),
            'items.itemColumnValues.column'
        ]);

        // Resolve filters: query params override saved per-user filters
        $saved = UserBoardFilter::where('user_id', $user->id)->where('board_id', $board->id)->first();
        $assigneeParam = $request->input('assignee');
        if ($request->has('assignee')) {
            $filterUnassigned = $assigneeParam === 'unassigned';
            $filterAssigneeId = $filterUnassigned ? null : ($assigneeParam ? (int) $assigneeParam : null);
        } else {
            $filterUnassigned = $saved?->filter_unassigned ?? false;
            $filterAssigneeId = $saved?->assignee_id;
        }
        $filterType = $request->has('type') ? (in_array($request->string('type')->toString(), ['task', 'bug'], true) ? $request->string('type')->toString() : null) : ($saved?->item_type);

        return view('boards.show', [
            'board' => $board,
            'filterAssigneeId' => $filterAssigneeId,
            'filterUnassigned' => $filterUnassigned,
            'filterType' => $filterType,
        ]);
    }

    /**
     * Show board with specific item (clean URL format: /boards/{board}/ticket/{item_number})
     */
    public function showItem(Request $request, Board $board, int $item): View|RedirectResponse
    {
        $user = $request->user();

        // Load users relationship first to avoid N+1 query
        $board->load('users');

        // Admins can access all boards, regular users only assigned boards
        if (! $user->is_admin && ! $board->users->contains($user->id)) {
            abort(403, 'You do not have access to this board.');
        }

        // Find item by number (not ID)
        $itemModel = \App\Models\Item::where('board_id', $board->id)
            ->where('number', $item)
            ->first();

        if (!$itemModel) {
            // Item not found, redirect to board without item
            return redirect()->route('boards.show', ['board' => $board->id])
                ->with('error', 'Task not found.');
        }

        // Eager load all relationships in one query
        $board->load([
            'columns' => fn ($q) => $q->orderBy('position'),
            'groups' => fn ($q) => $q->orderBy('position'),
            'items.itemColumnValues.column'
        ]);

        // Resolve filters: query params override saved per-user filters
        $saved = UserBoardFilter::where('user_id', $user->id)->where('board_id', $board->id)->first();
        $assigneeParam = $request->input('assignee');
        if ($request->has('assignee')) {
            $filterUnassigned = $assigneeParam === 'unassigned';
            $filterAssigneeId = $filterUnassigned ? null : ($assigneeParam ? (int) $assigneeParam : null);
        } else {
            $filterUnassigned = $saved?->filter_unassigned ?? false;
            $filterAssigneeId = $saved?->assignee_id;
        }
        $filterType = $request->has('type') ? (in_array($request->string('type')->toString(), ['task', 'bug'], true) ? $request->string('type')->toString() : null) : ($saved?->item_type);

        // Get view from query parameter or default
        $view = $request->input('view', 'kanban');

        return view('boards.show', [
            'board' => $board,
            'filterAssigneeId' => $filterAssigneeId,
            'filterUnassigned' => $filterUnassigned,
            'filterType' => $filterType,
            'selectedItemId' => $itemModel->id, // Pass item ID to Livewire component
        ]);
    }

    public function applyFilters(Request $request, Board $board): RedirectResponse
    {
        $user = $request->user();
        if (! $user->is_admin && ! $board->users->contains($user->id)) {
            abort(403, 'You do not have access to this board.');
        }

        $assigneeParam = $request->input('assignee');
        $filterUnassigned = $assigneeParam === 'unassigned';
        $assigneeId = $filterUnassigned || $assigneeParam === '' || $assigneeParam === null ? null : (int) $assigneeParam;
        $type = $request->input('type');
        $type = in_array($type, ['task', 'bug'], true) ? $type : null;

        UserBoardFilter::updateOrCreate(
            ['user_id' => $user->id, 'board_id' => $board->id],
            ['assignee_id' => $assigneeId, 'filter_unassigned' => $filterUnassigned, 'item_type' => $type]
        );

        $params = ['board' => $board, 'view' => $request->input('view', 'kanban')];
        if ($filterUnassigned) {
            $params['assignee'] = 'unassigned';
        } elseif ($assigneeId !== null) {
            $params['assignee'] = $assigneeId;
        }
        if ($type !== null) {
            $params['type'] = $type;
        }

        return redirect()->route('boards.show', $params);
    }

    public function destroy(Request $request, Board $board): RedirectResponse
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only administrators can delete boards.');
        }
        $board->delete();
        return redirect()->route('boards.index')->with('success', 'Board deleted.');
    }

    public function mentionableUsers(Request $request, Board $board): JsonResponse
    {
        // Check access
        $user = $request->user();
        if (! $user->is_admin && ! $board->users->contains($user->id)) {
            abort(403, 'You do not have access to this board.');
        }
        
        $mentionService = app(MentionService::class);
        $users = $mentionService->getMentionableUsers($board);
        
        return response()->json($users);
    }

    public function exportCsv(Request $request, Board $board): StreamedResponse
    {
        $user = $request->user();
        
        // Check access
        if (! $user->is_admin && ! $board->users->contains($user->id)) {
            abort(403, 'You do not have access to this board.');
        }

        // Load all items with relationships
        $items = $board->items()
            ->with(['group', 'assignee', 'creator', 'activities' => function ($q) {
                $q->with('user')->orderByDesc('created_at')->limit(1);
            }])
            ->orderBy('number', 'asc')
            ->get();

        $filename = str_replace([' ', '/'], ['_', '-'], $board->name) . '_' . date('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($items) {
            $file = fopen('php://output', 'w');
            
            // BOM for Excel UTF-8 compatibility
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Headers
            fputcsv($file, ['#', 'Name', 'Description', 'Status', 'Type', 'Assignee', 'Last Updated', 'Updated By']);
            
            // Data rows
            foreach ($items as $item) {
                $lastActivity = $item->activities->first();
                $updatedBy = $lastActivity?->user?->name ?? $item->creator?->name ?? '—';
                $lastUpdated = $item->updated_at ? $item->updated_at->format('M d, Y H:i') : '—';
                
                fputcsv($file, [
                    $item->number,
                    $item->name,
                    $item->description ?? '',
                    $item->group?->name ?? '—',
                    ucfirst($item->item_type ?? '—'),
                    $item->assignee?->name ?? '—',
                    $lastUpdated,
                    $updatedBy,
                ]);
            }
            
            fclose($file);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
