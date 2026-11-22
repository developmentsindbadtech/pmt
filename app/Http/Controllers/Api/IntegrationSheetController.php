<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sheet;
use App\Models\User;
use App\Services\IntegrationSheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationSheetController extends Controller
{
    public function index(Request $request, IntegrationSheetService $sheets): JsonResponse
    {
        $viewer = $this->viewer($request);

        return response()->json([
            'sheets' => $sheets->listFor($viewer),
        ]);
    }

    public function store(Request $request, IntegrationSheetService $sheets): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'sheetId' => 'required|integer|min:1',
            'meetingDate' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.externalKey' => 'required|string|max:255',
            'items.*.name' => 'required|string|max:255',
            'items.*.priority' => 'nullable|in:low,medium,high',
            'items.*.description' => 'nullable|string|max:10000',
            'items.*.assigneeEmail' => 'nullable|email|max:255',
            'items.*.dueAt' => 'nullable|date',
            'items.*.relatedTo' => 'nullable|string|max:1000',
        ]);

        $viewer = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($validated['email'])])
            ->first();
        if (! $viewer instanceof User) {
            return response()->json([
                'message' => 'No PMT user matches this Microsoft 365 account.',
            ], 404);
        }

        $sheet = Sheet::query()->find($validated['sheetId']);
        if (! $sheet instanceof Sheet) {
            return response()->json([
                'message' => 'Sheet not found.',
                'sheetId' => $validated['sheetId'],
            ], 404);
        }

        if (! $sheets->canWrite($viewer, $sheet)) {
            return response()->json([
                'message' => 'You do not have access to this sheet.',
            ], 403);
        }

        $externalKeys = collect($validated['items'])->pluck('externalKey');
        if ($externalKeys->count() !== $externalKeys->unique()->count()) {
            return response()->json([
                'message' => 'Duplicate externalKey values in the same request are not allowed.',
            ], 422);
        }

        return response()->json($sheets->appendRows(
            $sheet,
            $validated['meetingDate'] ?? null,
            $validated['items'],
        ));
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
