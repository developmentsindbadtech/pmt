<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Sheet;
use App\Models\User;
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
            ->withCount(['users' => function ($query) {
                $query->where('is_admin', false);
            }])
            ->with('users:id')
            ->orderBy('name', $sheetSort)
            ->get();

        $users = User::query()
            ->where('is_admin', false)
            ->orderBy('name')
            ->get();

        return view('user-management.index', [
            'boards' => $boards,
            'sheets' => $sheets,
            'users' => $users,
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

        $userIds = $validated['user_ids'] ?? [];
        $board->users()->sync($userIds);

        return redirect()->route('user-management.index')
            ->with('success', 'Board assignments updated successfully.');
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

        $userIds = $validated['user_ids'] ?? [];
        $sheet->users()->sync($userIds);

        return redirect()->route('user-management.index')
            ->with('success', 'Sheet assignments updated successfully.');
    }
}
