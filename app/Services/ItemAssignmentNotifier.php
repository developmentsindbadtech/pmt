<?php

namespace App\Services;

use App\Models\Board;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ItemAssignmentNotifier
{
    public function notify(Item $item, Board $board, User $assignedBy): void
    {
        if (! config('services.microsoft.client_id') || ! config('services.microsoft.client_secret')) {
            return;
        }

        if ($item->assignee_id === $assignedBy->id) {
            return;
        }

        if (! $item->relationLoaded('assignee')) {
            $item->load('assignee');
        }

        if (! $item->assignee || ! $item->assignee->email) {
            return;
        }

        $itemType = $item->isBug() ? 'Bug' : 'Task';
        $itemUrl = route('boards.show.item', [
            'board' => $board->id,
            'item' => $item->number,
            'view' => $board->view_type,
        ]);

        $htmlBody = view('emails.assignment', [
            'assignedBy' => $assignedBy,
            'item' => $item,
            'board' => $board,
            'itemUrl' => $itemUrl,
        ])->render();

        $subject = "You were assigned to {$itemType} #{$item->number}";

        try {
            $success = app(MicrosoftGraphMailService::class)->sendEmail(
                $item->assignee->email,
                $subject,
                $htmlBody
            );

            if (! $success) {
                Log::error("Failed to send assignment notification via Microsoft Graph to: {$item->assignee->email}");
            }
        } catch (\Exception $e) {
            Log::error('Failed to send assignment notification via Microsoft Graph: '.$e->getMessage());
        }
    }
}
