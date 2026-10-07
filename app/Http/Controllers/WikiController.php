<?php

namespace App\Http\Controllers;

use App\Models\WikiPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WikiController extends Controller
{
    public function index(Request $request): View
    {
        $term = trim((string) $request->query('q', ''));
        $pages = WikiPage::query()
            ->with(['author:id,name', 'editor:id,name'])
            ->when($term !== '', function ($query) use ($term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';
                $query->whereRaw("LOWER(title) LIKE ? ESCAPE '\\'", [$like]);
            })
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();

        return view('wiki.index', [
            'pages' => $pages,
            'term' => $term,
        ]);
    }

    public function create(): View
    {
        return view('wiki.form', [
            'page' => new WikiPage,
            'mode' => 'create',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $userId = $request->user()->id;

        $page = WikiPage::create([
            'title' => $validated['title'],
            'slug' => WikiPage::uniqueSlug($validated['title']),
            'body' => $validated['body'] ?? '',
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        return redirect()->route('wiki.show', $page)->with('success', 'Page published.');
    }

    public function show(WikiPage $wikiPage): View
    {
        $wikiPage->load(['author:id,name', 'editor:id,name']);

        return view('wiki.show', ['page' => $wikiPage]);
    }

    public function edit(WikiPage $wikiPage): View
    {
        return view('wiki.form', [
            'page' => $wikiPage,
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, WikiPage $wikiPage): RedirectResponse
    {
        $validated = $this->validated($request);

        $wikiPage->update([
            'title' => $validated['title'],
            'body' => $validated['body'] ?? '',
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->route('wiki.show', $wikiPage)->with('success', 'Page updated.');
    }

    public function destroy(Request $request, WikiPage $wikiPage): RedirectResponse
    {
        abort_unless($wikiPage->canDelete($request->user()), 403);

        $wikiPage->delete();

        return redirect()->route('wiki.index')->with('success', 'Page deleted.');
    }

    /** @return array{title: string, body: string|null} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:120',
            'body' => 'nullable|string|max:50000',
        ]);
    }
}
