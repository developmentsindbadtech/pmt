<?php

namespace App\Services;

use App\Models\Board;
use App\Models\Sheet;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class AccessGrantNotifier
{
    public function notifyNewBoardMembers(Board $board, array $previousUserIds, array $newUserIds, User $grantedBy): void
    {
        $this->notify(
            resourceLabel: 'board',
            resourceName: $board->name,
            resourceUrl: route('boards.show', $board),
            previousUserIds: $previousUserIds,
            newUserIds: $newUserIds,
            grantedBy: $grantedBy,
            emailView: 'emails.board-access',
            subject: 'You were given access to the board "'.$board->name.'"',
            viewData: ['board' => $board],
        );
    }

    public function notifyNewSheetMembers(Sheet $sheet, array $previousUserIds, array $newUserIds, User $grantedBy): void
    {
        $this->notify(
            resourceLabel: 'sheet',
            resourceName: $sheet->name,
            resourceUrl: route('sheets.show', $sheet),
            previousUserIds: $previousUserIds,
            newUserIds: $newUserIds,
            grantedBy: $grantedBy,
            emailView: 'emails.sheet-access',
            subject: 'You were given access to the sheet "'.$sheet->name.'"',
            viewData: ['sheet' => $sheet],
        );
    }

    /**
     * @param  array<int>  $previousUserIds
     * @param  array<int>  $newUserIds
     * @param  array<string, mixed>  $viewData
     */
    private function notify(
        string $resourceLabel,
        string $resourceName,
        string $resourceUrl,
        array $previousUserIds,
        array $newUserIds,
        User $grantedBy,
        string $emailView,
        string $subject,
        array $viewData,
    ): void {
        if (! config('services.microsoft.client_id') || ! config('services.microsoft.client_secret')) {
            return;
        }

        $addedIds = array_values(array_diff(
            array_map('intval', $newUserIds),
            array_map('intval', $previousUserIds),
        ));

        if ($addedIds === []) {
            return;
        }

        $recipients = User::query()
            ->whereIn('id', $addedIds)
            ->where('id', '!=', $grantedBy->id)
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $mail = app(MicrosoftGraphMailService::class);

        foreach ($recipients as $user) {
            if (! $user->email) {
                continue;
            }

            try {
                $html = view($emailView, array_merge($viewData, [
                    'grantedBy' => $grantedBy,
                    'recipient' => $user,
                    'resourceLabel' => $resourceLabel,
                    'resourceName' => $resourceName,
                    'resourceUrl' => $resourceUrl,
                ]))->render();

                $mail->sendEmail($user->email, $subject, $html);
            } catch (\Throwable $e) {
                Log::error("Failed to send {$resourceLabel} access email to {$user->email}: ".$e->getMessage());
            }
        }
    }
}
