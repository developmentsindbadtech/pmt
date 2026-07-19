<?php

namespace App\Http\Controllers;

use App\Models\Sheet;
use App\Models\SheetColumn;
use App\Services\MentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SheetController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        // Everyone (including admins) only sees sheets they own or are assigned to.
        // Admins manage other people's sheets from User Management without crowding this list.
        $sheets = Sheet::query()
            ->visibleTo($user)
            ->with(['creator:id,name'])
            ->withCount(['rows', 'columns'])
            ->orderBy('name')
            ->limit(100)
            ->get();

        return view('sheets.index', [
            'sheets' => $sheets,
            'isAdmin' => (bool) $user->is_admin,
        ]);
    }

    public function create(): View
    {
        return view('sheets.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $sheet = Sheet::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        // Ensure the creator can access the sheet they just made.
        $sheet->users()->syncWithoutDetaching([$request->user()->id]);

        // Seed a Monday-style PM starter set: name + status + owner + due + priority.
        $starterColumns = [
            ['name' => 'Title', 'type' => 'text', 'options' => null, 'position' => 0],
            ['name' => 'Status', 'type' => 'status', 'options' => ['To Do', 'In Progress', 'Stuck', 'Done'], 'position' => 1],
            ['name' => 'Owner', 'type' => 'person', 'options' => null, 'position' => 2],
            ['name' => 'Due', 'type' => 'date', 'options' => null, 'position' => 3],
            ['name' => 'Priority', 'type' => 'status', 'options' => ['Critical', 'High', 'Medium', 'Low'], 'position' => 4],
        ];
        foreach ($starterColumns as $col) {
            SheetColumn::create([
                'sheet_id' => $sheet->id,
                'name' => $col['name'],
                'type' => $col['type'],
                'options' => $col['options'],
                'position' => $col['position'],
            ]);
        }

        return redirect()->route('sheets.show', $sheet)->with('success', 'Sheet created.');
    }

    public function show(Request $request, Sheet $sheet): View
    {
        $this->authorizeAccess($request, $sheet);

        return view('sheets.show', ['sheet' => $sheet]);
    }

    public function destroy(Request $request, Sheet $sheet): RedirectResponse
    {
        $user = $request->user();
        $isOwner = (int) $sheet->created_by === (int) $user->id;
        if (! $user->is_admin && ! $isOwner) {
            abort(403, 'Only the sheet owner or an admin can delete this sheet.');
        }

        $sheet->delete();

        return redirect()->route('sheets.index')->with('success', 'Sheet deleted.');
    }

    public function mentionableUsers(Request $request, Sheet $sheet): JsonResponse
    {
        $this->authorizeAccess($request, $sheet);

        $users = app(MentionService::class)->getMentionableUsersForSheet($sheet);

        return response()->json($users);
    }

    /**
     * Admins can open any sheet (for support / access management).
     * Everyone else needs to be the owner or an assigned member.
     */
    private function authorizeAccess(Request $request, Sheet $sheet): void
    {
        $user = $request->user();
        if ($user->is_admin) {
            return;
        }
        if ((int) $sheet->created_by === (int) $user->id) {
            return;
        }
        if (! $sheet->users()->where('users.id', $user->id)->exists()) {
            abort(403, 'You do not have access to this sheet.');
        }
    }
}
