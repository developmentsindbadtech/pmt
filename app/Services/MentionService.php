<?php

namespace App\Services;

use App\Models\Board;
use App\Models\User;

class MentionService
{
    /**
     * Extract @mentions from text (format: @username or @name)
     * Returns array of user IDs that were mentioned
     */
    public function extractMentions(string $text, Board $board): array
    {
        // Match @username patterns
        preg_match_all('/@(\w+)/', $text, $matches);
        
        if (empty($matches[1])) {
            return [];
        }
        
        $mentionedUsernames = array_unique($matches[1]);
        $mentionedUserIds = [];
        
        // Get users assigned to this board OR admins
        $availableUsers = User::query()
            ->where(function ($query) use ($board) {
                $query->whereHas('boards', function ($q) use ($board) {
                    $q->where('boards.id', $board->id);
                })
                ->orWhere('is_admin', true);
            })
            ->get(['id', 'name', 'email']);
        
        // Match usernames to user IDs
        foreach ($mentionedUsernames as $username) {
            // Try exact match first (case-insensitive)
            $user = $availableUsers->first(function ($user) use ($username) {
                $name = strtolower(str_replace(' ', '', $user->name));
                $search = strtolower($username);
                return $name === $search;
            });
            
            // If no exact match, try partial match
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
     * Get users available for mentioning in a board
     */
    public function getMentionableUsers(Board $board): array
    {
        return User::query()
            ->where(function ($query) use ($board) {
                $query->whereHas('boards', function ($q) use ($board) {
                    $q->where('boards.id', $board->id);
                })
                ->orWhere('is_admin', true);
            })
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'email'])
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'search' => strtolower(str_replace(' ', '', $user->name)), // For autocomplete
                ];
            })
            ->toArray();
    }
}
