<?php

namespace App\Services;

use App\Models\Board;
use App\Models\Sheet;
use App\Models\User;
use App\Models\UserNavPreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class NavList
{
    public const LIMIT = 30;

    /**
     * @return array{items: Collection<int, Board>, hidden_count: int}
     */
    public static function boards(User $user): array
    {
        $query = Board::query();
        if (! $user->is_admin) {
            $query->whereHas('users', fn ($q) => $q->where('users.id', $user->id));
        }

        return self::prioritize($query, $user, 'board');
    }

    /**
     * @return array{items: Collection<int, Sheet>, hidden_count: int}
     */
    public static function sheets(User $user): array
    {
        return self::prioritize(Sheet::query()->visibleTo($user), $user, 'sheet');
    }

    /**
     * @return array{boards: list<array<string, mixed>>, sheets: list<array<string, mixed>>}
     */
    public static function search(User $user, string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return ['boards' => [], 'sheets' => []];
        }

        $like = '%'.self::escapeLike(mb_strtolower($term)).'%';

        $boards = Board::query();
        if (! $user->is_admin) {
            $boards->whereHas('users', fn ($q) => $q->where('users.id', $user->id));
        }

        return [
            'boards' => self::decorate(
                $boards->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])->orderBy('name')->limit(8)->get(['id', 'name']),
                $user,
                'board'
            ),
            'sheets' => self::decorate(
                Sheet::query()->visibleTo($user)->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])->orderBy('name')->limit(8)->get(['id', 'name']),
                $user,
                'sheet'
            ),
        ];
    }

    /**
     * @param  Builder<Board>|Builder<Sheet>  $query
     * @return array{items: Collection<int, Board>|Collection<int, Sheet>, hidden_count: int}
     */
    private static function prioritize(Builder $query, User $user, string $type): array
    {
        $prefs = UserNavPreference::query()
            ->where('user_id', $user->id)
            ->where('subject_type', $type)
            ->get(['subject_id', 'pinned', 'hidden']);

        $hiddenIds = $prefs->where('hidden', true)->pluck('subject_id')->map(fn ($id) => (int) $id)->all();
        $pinnedIds = $prefs->where('pinned', true)->where('hidden', false)->pluck('subject_id')->map(fn ($id) => (int) $id)->all();

        $visible = clone $query;
        if ($hiddenIds !== []) {
            $visible->whereNotIn('id', $hiddenIds);
        }

        $pinnedItems = $pinnedIds === []
            ? collect()
            : (clone $visible)->whereIn('id', $pinnedIds)->orderBy('name')->get(['id', 'name']);

        $room = max(0, self::LIMIT - $pinnedItems->count());
        $rest = $room === 0
            ? collect()
            : (clone $visible)
                ->when($pinnedIds !== [], fn ($q) => $q->whereNotIn('id', $pinnedIds))
                ->orderBy('name')
                ->limit($room)
                ->get(['id', 'name']);

        $pinnedItems->each(fn ($item) => $item->setAttribute('nav_pinned', true));
        $rest->each(fn ($item) => $item->setAttribute('nav_pinned', false));

        return [
            'items' => $pinnedItems->concat($rest)->values(),
            'hidden_count' => $hiddenIds === [] ? 0 : (clone $query)->whereIn('id', $hiddenIds)->count(),
        ];
    }

    /**
     * @param  Collection<int, Board|Sheet>  $items
     * @return list<array{id: int, name: string, pinned: bool, hidden: bool}>
     */
    private static function decorate(Collection $items, User $user, string $type): array
    {
        $prefs = UserNavPreference::query()
            ->where('user_id', $user->id)
            ->where('subject_type', $type)
            ->whereIn('subject_id', $items->pluck('id')->all() ?: [0])
            ->get()
            ->keyBy('subject_id');

        return $items->map(function ($item) use ($prefs) {
            $pref = $prefs->get($item->id);

            return [
                'id' => $item->id,
                'name' => $item->name,
                'pinned' => (bool) ($pref->pinned ?? false),
                'hidden' => (bool) ($pref->hidden ?? false),
            ];
        })->all();
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
