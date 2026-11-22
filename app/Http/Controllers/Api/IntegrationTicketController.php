<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\User;
use App\Services\IntegrationTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationTicketController extends Controller
{
    public function index(Request $request, IntegrationTicketService $tickets): JsonResponse
    {
        $validated = $request->validate([
            'boardName' => 'required|string|max:255',
        ]);

        $resolved = $tickets->resolveBoard($validated['boardName']);
        if ($resolved['status'] !== 200) {
            return response()->json($resolved['payload'], $resolved['status']);
        }

        /** @var \App\Models\Board $board */
        $board = $resolved['payload']['board'];

        return response()->json($tickets->listOpenTickets($board));
    }

    public function store(Request $request, IntegrationTicketService $tickets): JsonResponse
    {
        $validated = $request->validate([
            'boardId' => 'nullable|integer|min:1',
            'boardName' => 'required_without:boardId|nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'meetingDate' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.externalKey' => 'required|string|max:255',
            'items.*.name' => 'required|string|max:255',
            'items.*.itemType' => 'required|in:task,bug',
            'items.*.priority' => 'required|in:low,medium,high',
            'items.*.description' => 'nullable|string|max:10000',
            'items.*.assigneeEmail' => 'nullable|email|max:255',
            'items.*.dueAt' => 'nullable|date',
            'items.*.devTag' => 'nullable|string|max:64',
            'items.*.relatedTo' => 'nullable|string|max:1000',
        ]);

        if (! empty($validated['boardId'])) {
            $board = Board::query()->find($validated['boardId']);
            if (! $board instanceof Board) {
                return response()->json([
                    'message' => 'Board not found.',
                    'boardId' => $validated['boardId'],
                ], 404);
            }
        } else {
            $resolved = $tickets->resolveBoard((string) $validated['boardName']);
            if ($resolved['status'] !== 200) {
                return response()->json($resolved['payload'], $resolved['status']);
            }

            /** @var Board $board */
            $board = $resolved['payload']['board'];
        }

        if (! empty($validated['email'])) {
            $denied = $this->denyUnlessBoardMember((string) $validated['email'], $board);
            if ($denied instanceof JsonResponse) {
                return $denied;
            }
        }

        $board->load('columns');

        $externalKeys = collect($validated['items'])->pluck('externalKey');
        if ($externalKeys->count() !== $externalKeys->unique()->count()) {
            return response()->json([
                'message' => 'Duplicate externalKey values in the same request are not allowed.',
            ], 422);
        }

        $payload = $tickets->upsertTickets(
            $board,
            $request->user(),
            $validated['meetingDate'] ?? null,
            $validated['items'],
        );

        return response()->json($payload, 200);
    }

    /**
     * When the analyzer names the Microsoft 365 account, only an admin or a
     * board member may create tickets there. Calls that omit the email keep
     * the previous token-only behavior.
     */
    private function denyUnlessBoardMember(string $email, Board $board): ?JsonResponse
    {
        $viewer = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->first();
        if (! $viewer instanceof User) {
            return response()->json([
                'message' => 'No PMT user matches this Microsoft 365 account.',
            ], 404);
        }
        if ($viewer->isAdmin()) {
            return null;
        }
        $member = $board->users()->where('users.id', $viewer->id)->exists();
        if ($member) {
            return null;
        }

        return response()->json([
            'message' => 'You do not have access to this board.',
        ], 403);
    }
}
