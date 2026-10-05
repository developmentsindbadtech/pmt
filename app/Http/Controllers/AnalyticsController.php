<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Sheet;
use App\Services\FlowAnalytics;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function board(Request $request, Board $board): View
    {
        $this->authorizeBoard($request, $board);

        [$from, $to] = $this->range($request);

        return view('analytics.show', [
            'report' => FlowAnalytics::forBoard($board, $from, $to),
        ]);
    }

    public function sheet(Request $request, Sheet $sheet): View
    {
        $this->authorizeSheet($request, $sheet);

        [$from, $to] = $this->range($request);

        return view('analytics.show', [
            'report' => FlowAnalytics::forSheet($sheet, $from, $to),
        ]);
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function range(Request $request): array
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        return [
            isset($validated['from']) ? Carbon::parse($validated['from']) : null,
            isset($validated['to']) ? Carbon::parse($validated['to']) : null,
        ];
    }

    private function authorizeBoard(Request $request, Board $board): void
    {
        $user = $request->user();
        $board->loadMissing('users');
        if (! $user->is_admin && ! $board->users->contains($user->id)) {
            abort(403, 'You do not have access to this board.');
        }
    }

    private function authorizeSheet(Request $request, Sheet $sheet): void
    {
        $user = $request->user();
        if (! $user->is_admin
            && (int) $sheet->created_by !== (int) $user->id
            && ! $sheet->users()->where('users.id', $user->id)->exists()) {
            abort(403, 'You do not have access to this sheet.');
        }
    }
}
