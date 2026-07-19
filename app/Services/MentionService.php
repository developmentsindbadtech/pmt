<?php

namespace App\Services;

use App\Models\Board;
use App\Models\Sheet;
use App\Models\User;
use Illuminate\Support\Collection;

class MentionService
{
    /**
     * Extract @mentions from text (format: @Name or @FirstLast).
     * Returns array of user IDs that were mentioned among $availableUsers.
     */
    public function extractMentionsFromUsers(string $text, Collection $availableUsers): array
    {
        preg_match_all('/@(\w+)/', $text, $matches);

        if (empty($matches[1])) {
            return [];
        }

        $mentionedUsernames = array_unique($matches[1]);
        $mentionedUserIds = [];

        foreach ($mentionedUsernames as $username) {
            $user = $availableUsers->first(function ($user) use ($username) {
                $name = strtolower(str_replace(' ', '', $user->name));
                $search = strtolower($username);

                return $name === $search;
            });

            if (! $user) {
                $user = $availableUsers->first(function ($user) use ($username) {
                    $name = strtolower(str_replace(' ', '', $user->name));
                    $search = strtolower($username);

                    return strpos($name, $search) !== false || strpos($search, $name) !== false;
                });
            }

            if ($user) {
                $mentionedUserIds[] = $user->id;
            }
        }

        return array_unique($mentionedUserIds);
    }

    /**
     * Extract @mentions for a board context.
     */
    public function extractMentions(string $text, Board $board): array
    {
        return $this->extractMentionsFromUsers($text, $this->mentionableUsersQueryForBoard($board)->get(['id', 'name', 'email']));
    }

    /**
     * Extract @mentions for a sheet context.
     */
    public function extractMentionsForSheet(string $text, Sheet $sheet): array
    {
        return $this->extractMentionsFromUsers($text, $this->mentionableUsersQueryForSheet($sheet)->get(['id', 'name', 'email']));
    }

    /**
     * Get users available for mentioning in a board.
     */
    public function getMentionableUsers(Board $board): array
    {
        return $this->formatMentionableUsers(
            $this->mentionableUsersQueryForBoard($board)->orderBy('name')->get(['id', 'name', 'email'])
        );
    }

    /**
     * Get users available for mentioning in a sheet.
     */
    public function getMentionableUsersForSheet(Sheet $sheet): array
    {
        return $this->formatMentionableUsers(
            $this->mentionableUsersQueryForSheet($sheet)->orderBy('name')->get(['id', 'name', 'email'])
        );
    }

    private function mentionableUsersQueryForBoard(Board $board)
    {
        return User::query()
            ->where(function ($query) use ($board) {
                $query->whereHas('boards', function ($q) use ($board) {
                    $q->where('boards.id', $board->id);
                })
                    ->orWhere('is_admin', true);
            });
    }

    private function mentionableUsersQueryForSheet(Sheet $sheet)
    {
        return User::query()
            ->where(function ($query) use ($sheet) {
                $query->whereHas('sheets', function ($q) use ($sheet) {
                    $q->where('sheets.id', $sheet->id);
                })
                    ->orWhere('is_admin', true);
            });
    }

    private function formatMentionableUsers(Collection $users): array
    {
        return $users
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'search' => strtolower(str_replace(' ', '', $user->name)),
                ];
            })
            ->values()
            ->all();
    }
}
