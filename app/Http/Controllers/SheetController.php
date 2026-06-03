<?php

namespace App\Http\Controllers;

use App\Models\Sheet;
use App\Models\SheetColumn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SheetController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $query = Sheet::query()->withCount(['rows', 'columns']);

        // Admins see all sheets; regular users only see assigned sheets.
        if (! $user->is_admin) {
            $query->whereHas('users', function ($q) use ($user) {
                $q->where('users.id', $user->id);
            });
        }

        $sheets = $query->orderBy('name')->limit(100)->get();

        return view('sheets.index', ['sheets' => $sheets]);
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

        // Seed a couple of starter columns so the grid is usable immediately.
        SheetColumn::create([
            'sheet_id' => $sheet->id,
            'name' => 'Title',
            'type' => 'text',
            'position' => 0,
        ]);
        SheetColumn::create([
            'sheet_id' => $sheet->id,
            'name' => 'Status',
            'type' => 'status',
            'options' => ['To Do', 'In Progress', 'Done'],
            'position' => 1,
        ]);

        return redirect()->route('sheets.show', $sheet)->with('success', 'Sheet created.');
    }

    public function show(Request $request, Sheet $sheet): View
    {
        $this->authorizeAccess($request, $sheet);

        return view('sheets.show', ['sheet' => $sheet]);
    }

    public function destroy(Request $request, Sheet $sheet): RedirectResponse
    {
        $this->authorizeAccess($request, $sheet);

        $sheet->delete();

        return redirect()->route('sheets.index')->with('success', 'Sheet deleted.');
    }

    /** Admins can access any sheet; regular users only assigned ones. */
    private function authorizeAccess(Request $request, Sheet $sheet): void
    {
        $user = $request->user();
        if ($user->is_admin) {
            return;
        }
        if (! $sheet->users()->where('users.id', $user->id)->exists()) {
            abort(403, 'You do not have access to this sheet.');
        }
    }
}
