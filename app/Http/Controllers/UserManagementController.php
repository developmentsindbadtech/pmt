<?php

namespace App\Http\Controllers;

use App\Models\Board;
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

        $boards = Board::query()
            ->withCount(['users' => function ($query) {
                $query->where('is_admin', false);
            }])
            ->orderBy('name', 'asc')
            ->get();

        $users = User::query()
            ->where('is_admin', false)
            ->orderBy('name')
            ->get();

        return view('user-management.index', [
            'boards' => $boards,
            'users' => $users,
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
}
