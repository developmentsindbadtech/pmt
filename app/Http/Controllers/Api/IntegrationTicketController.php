<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
            'boardName' => 'required|string|max:255',
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

        $resolved = $tickets->resolveBoard($validated['boardName']);
        if ($resolved['status'] !== 200) {
            return response()->json($resolved['payload'], $resolved['status']);
        }

        /** @var \App\Models\Board $board */
        $board = $resolved['payload']['board'];
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
}
