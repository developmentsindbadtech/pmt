<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Sheet;
use App\Models\UserNavPreference;
use App\Providers\AppServiceProvider;
use App\Services\NavList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NavController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $term = (string) $request->query('q', '');

        return response()->json(NavList::search($request->user(), $term));
    }

    public function update(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:board,sheet',
            'id' => 'required|integer',
            'action' => 'required|in:pin,hide',
        ]);

        $this->assertVisible($request, $data['type'], (int) $data['id']);

        $pref = UserNavPreference::query()->firstOrNew([
            'user_id' => $request->user()->id,
            'subject_type' => $data['type'],
            'subject_id' => (int) $data['id'],
        ]);

        if ($data['action'] === 'pin') {
            $pref->pinned = ! $pref->pinned;
            if ($pref->pinned) {
                $pref->hidden = false;
            }
        } else {
            $pref->hidden = ! $pref->hidden;
            if ($pref->hidden) {
                $pref->pinned = false;
            }
        }

        if (! $pref->pinned && ! $pref->hidden) {
            if ($pref->exists) {
                $pref->delete();
            }
        } else {
            $pref->save();
        }

        AppServiceProvider::bustNavCache();

        if ($request->expectsJson()) {
            return response()->json([
                'pinned' => (bool) $pref->pinned,
                'hidden' => (bool) $pref->hidden,
            ]);
        }

        return back();
    }

    private function assertVisible(Request $request, string $type, int $id): void
    {
        $user = $request->user();

        if ($type === 'board') {
            $board = Board::query()->findOrFail($id);
            if (! $user->is_admin && ! $board->users()->where('users.id', $user->id)->exists()) {
                abort(403);
            }

            return;
        }

        $visible = Sheet::query()->visibleTo($user)->whereKey($id)->exists();
        if (! $visible) {
            abort(403);
        }
    }
}
