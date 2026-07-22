<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Sheet;
use App\Models\User;
use App\Services\AccessGrantNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function index(Request $request): View
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only administrators can access user management.');
        }

        // Sort direction for the board/sheet name lists (default A→Z).
        $boardSort = $request->input('board_sort') === 'desc' ? 'desc' : 'asc';
        $sheetSort = $request->input('sheet_sort') === 'desc' ? 'desc' : 'asc';

        $boards = Board::query()
            ->withCount(['users' => function ($query) {
                $query->where('is_admin', false);
            }])
            ->with('users:id')
            ->orderBy('name', $boardSort)
            ->get();

        $sheets = Sheet::query()
            ->with(['creator:id,name,email', 'users:id'])
            ->withCount(['users'])
            ->orderBy('name', $sheetSort)
            ->get();

        // Boards: non-admins only (admins already see every board in the sidebar).
        $users = User::query()
            ->where('is_admin', false)
            ->orderBy('name')
            ->get();

        // Sheets: everyone, including admins — personal Sheets list is opt-in via assignment.
        $sheetUsers = User::query()
            ->orderBy('name')
            ->get();

        return view('user-management.index', [
            'boards' => $boards,
            'sheets' => $sheets,
            'users' => $users,
            'sheetUsers' => $sheetUsers,
            'boardSort' => $boardSort,
            'sheetSort' => $sheetSort,
        ]);
    }

    public function update(Request $request, Board $board): RedirectResponse
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only administrators can manage board assignments.');
        }

        $validated = $request->validate([
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $userIds = array_map('intval', $validated['user_ids'] ?? []);
        $previousIds = $board->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $board->users()->sync($userIds);

        app(AccessGrantNotifier::class)->notifyNewBoardMembers(
            $board,
            $previousIds,
            $userIds,
            $request->user()
        );

        return redirect()->route('user-management.index')
            ->withFragment('boards')
            ->with('success', 'Board assignments updated. Newly added people are emailed when mail is configured.');
    }

    public function updateSheet(Request $request, Sheet $sheet): RedirectResponse
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only administrators can manage sheet assignments.');
        }

        $validated = $request->validate([
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        // Owner always keeps access; anyone else (including admins) is opt-in for the personal Sheets list.
        $userIds = collect($validated['user_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($sheet->created_by) {
            $userIds->push((int) $sheet->created_by);
        }

        $assignable = User::query()
            ->whereIn('id', $userIds->all())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $previousIds = $sheet->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $sheet->users()->sync($assignable);

        app(AccessGrantNotifier::class)->notifyNewSheetMembers(
            $sheet,
            $previousIds,
            $assignable,
            $request->user()
        );

        return redirect()->route('user-management.index', [
            'sheet_sort' => $request->input('sheet_sort', 'asc'),
            'board_sort' => $request->input('board_sort', 'asc'),
        ])
            ->withFragment('sheets')
            ->with('success', 'Sheet access updated. Newly added people are emailed when mail is configured.');
    }
}
