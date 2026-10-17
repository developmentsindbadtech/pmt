<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\User;
use App\Services\FlowAnalytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class IntegrationAnalyticsController extends Controller
{
    public function boards(Request $request): JsonResponse
    {
        $viewer = $this->viewer($request);
        $boards = $viewer->isAdmin()
            ? Board::query()->orderBy('name')->get(['id', 'name'])
            : $viewer->boards()->orderBy('name')->get(['boards.id', 'boards.name']);

        return response()->json([
            'boards' => $boards->map(fn (Board $board) => [
                'id' => $board->id,
                'name' => $board->name,
            ])->values(),
        ]);
    }

    public function show(Request $request, Board $board): JsonResponse
    {
        $viewer = $this->viewer($request);
        $board->loadMissing('users');
        if (! $viewer->isAdmin() && ! $board->users->contains($viewer->id)) {
            return response()->json([
                'message' => 'You do not have access to this board.',
            ], 403);
        }

        $range = $request->query('range') === 'month' ? 'month' : 'week';
        $from = $range === 'month'
            ? Carbon::now()->subDays(29)->startOfDay()
            : Carbon::now()->subDays(6)->startOfDay();
        $report = FlowAnalytics::forBoard($board, $from, Carbon::now());

        return response()->json([
            'board' => [
                'id' => $board->id,
                'name' => $report['title'],
                'url' => route('boards.analytics', $board),
            ],
            'range' => $range,
            'from' => $report['from'],
            'to' => $report['to'],
            'fromLabel' => $report['fromLabel'],
            'toLabel' => $report['toLabel'],
            'open' => $report['open'],
            'closed' => $report['closed'],
            'overdue' => $report['overdue'],
            'unassigned' => $report['unassigned'],
            'aging' => $report['aging'],
            'hiddenAging' => $report['hiddenAging'] ?? [],
        ]);
    }

    private function viewer(Request $request): User
    {
        $email = mb_strtolower(trim((string) $request->query('email', '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(response()->json([
                'message' => 'A Microsoft 365 email is required.',
            ], 422));
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user instanceof User) {
            abort(response()->json([
                'message' => 'No PMT user matches this Microsoft 365 account.',
            ], 404));
        }

        return $user;
    }
}
