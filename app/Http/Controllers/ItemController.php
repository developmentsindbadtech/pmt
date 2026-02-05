<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Item;
use App\Models\ItemActivity;
use App\Models\ItemComment;
use App\Models\ItemColumnValue;
use App\Models\User;
use App\Services\MentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class ItemController extends Controller
{
    public function store(Request $request, Board $board): RedirectResponse
    {

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'group_id' => 'nullable|exists:groups,id',
            'item_type' => 'nullable|in:task,bug',
            'assignee_id' => 'nullable|exists:users,id',
        ]);

        $maxPosition = $board->items()->when(isset($validated['group_id']), function ($q) use ($validated) {
            $q->where('group_id', $validated['group_id']);
        })->max('position') ?? -1;

        $nextNumber = ((int) $board->items()->max('number')) + 1;

        $item = Item::create([
            'board_id' => $board->id,
            'number' => $nextNumber,
            'name' => $validated['name'],
            'item_type' => $validated['item_type'] ?? 'task',
            'group_id' => $validated['group_id'] ?? $board->groups()->first()?->id,
            'position' => $maxPosition + 1,
            'created_by' => $request->user()->id,
            'assignee_id' => $validated['assignee_id'] ?? null,
        ]);
        
        // Create activity record for item creation
        ItemActivity::create([
            'item_id' => $item->id,
            'user_id' => $request->user()->id,
            'type' => 'created',
            'field' => null,
            'old_value' => null,
            'new_value' => null,
        ]);
        
        // Send assignment notification if assignee is set
        if ($item->assignee_id && $item->assignee_id != $request->user()->id) {
            $this->sendAssignmentNotification($item, $board, $request->user());
        }

        $statusColumn = $board->columns()->where('type', 'status')->first();
        if ($statusColumn) {
            $defaultStatus = $statusColumn->settings['options'][0] ?? 'To Do';
            ItemColumnValue::create([
                'item_id' => $item->id,
                'column_id' => $statusColumn->id,
                'value' => ['text' => $defaultStatus],
            ]);
        }

        foreach ($board->columns()->where('type', '!=', 'status')->get() as $col) {
            if ($col->name === 'Name') {
                ItemColumnValue::create([
                    'item_id' => $item->id,
                    'column_id' => $col->id,
                    'value' => ['text' => $item->name],
                ]);
            }
        }

        return redirect()->route('boards.show', ['board' => $board, 'view' => request('view', $board->view_type)])
            ->with('success', 'Item added.');
    }

    public function update(Request $request, Board $board, Item $item): RedirectResponse
    {
        if ($item->board_id !== $board->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'group_id' => 'nullable|exists:groups,id',
            'item_type' => 'sometimes|in:task,bug',
            'description' => 'nullable|string|max:10000',
            'repro_steps' => 'nullable|string|max:10000',
            'assignee_id' => 'nullable|exists:users,id',
        ]);

        // When changing type: copy description ↔ repro_steps so content is not lost
        $newType = $validated['item_type'] ?? $item->item_type;
        if ($newType !== $item->item_type) {
            if ($newType === 'bug') {
                // Task → Bug: copy description into repro_steps (if not already provided)
                $validated['repro_steps'] = $validated['repro_steps'] ?? $item->description;
            } else {
                // Bug → Task: copy repro_steps into description (if not already provided)
                $validated['description'] = $validated['description'] ?? $item->repro_steps;
            }
        }

        $allowed = ['name', 'group_id', 'item_type', 'description', 'repro_steps', 'assignee_id'];
        $updates = array_intersect_key($validated, array_flip($allowed));
        if (! empty($updates)) {
            // Store old values before update for tracking changes
            $oldValues = [
                'name' => $item->name,
                'group_id' => $item->group_id,
                'item_type' => $item->item_type,
                'description' => $item->description,
                'repro_steps' => $item->repro_steps,
                'assignee_id' => $item->assignee_id,
            ];
            
            $oldAssigneeId = $item->assignee_id;
            $oldGroupId = $item->group_id;
            
            $item->update($updates);
            $item->refresh();
            
            // Track changes and create activity records
            $user = $request->user();
            
            // Track name changes
            if (isset($updates['name']) && $oldValues['name'] !== $updates['name']) {
                ItemActivity::create([
                    'item_id' => $item->id,
                    'user_id' => $user->id,
                    'type' => 'updated',
                    'field' => 'name',
                    'old_value' => $oldValues['name'],
                    'new_value' => $updates['name'],
                ]);
            }
            
            // Track status/group changes
            if (isset($updates['group_id']) && $oldGroupId != $updates['group_id']) {
                $oldGroup = $oldGroupId ? \App\Models\Group::find($oldGroupId) : null;
                $newGroup = $updates['group_id'] ? \App\Models\Group::find($updates['group_id']) : null;
                
                ItemActivity::create([
                    'item_id' => $item->id,
                    'user_id' => $user->id,
                    'type' => 'status_changed',
                    'field' => 'group_id',
                    'old_value' => $oldGroup ? $oldGroup->name : 'Unassigned',
                    'new_value' => $newGroup ? $newGroup->name : 'Unassigned',
                ]);
            }
            
            // Track type changes
            if (isset($updates['item_type']) && $oldValues['item_type'] !== $updates['item_type']) {
                ItemActivity::create([
                    'item_id' => $item->id,
                    'user_id' => $user->id,
                    'type' => 'updated',
                    'field' => 'item_type',
                    'old_value' => $oldValues['item_type'],
                    'new_value' => $updates['item_type'],
                ]);
            }
            
            // Track description changes
            if (isset($updates['description']) && $oldValues['description'] !== $updates['description']) {
                ItemActivity::create([
                    'item_id' => $item->id,
                    'user_id' => $user->id,
                    'type' => 'description_changed',
                    'field' => 'description',
                    'old_value' => $oldValues['description'],
                    'new_value' => $updates['description'],
                ]);
            }
            
            // Track repro_steps changes
            if (isset($updates['repro_steps']) && $oldValues['repro_steps'] !== $updates['repro_steps']) {
                ItemActivity::create([
                    'item_id' => $item->id,
                    'user_id' => $user->id,
                    'type' => 'repro_steps_changed',
                    'field' => 'repro_steps',
                    'old_value' => $oldValues['repro_steps'],
                    'new_value' => $updates['repro_steps'],
                ]);
            }
            
            // Track assignee changes
            if (isset($updates['assignee_id']) && $oldAssigneeId != $updates['assignee_id']) {
                $oldAssignee = $oldAssigneeId ? \App\Models\User::find($oldAssigneeId) : null;
                $newAssignee = $updates['assignee_id'] ? \App\Models\User::find($updates['assignee_id']) : null;
                
                ItemActivity::create([
                    'item_id' => $item->id,
                    'user_id' => $user->id,
                    'type' => 'assigned',
                    'field' => 'assignee_id',
                    'old_value' => $oldAssignee ? $oldAssignee->name : 'Unassigned',
                    'new_value' => $newAssignee ? $newAssignee->name : 'Unassigned',
                ]);
                
                // Send assignment notification if assignee changed and is not the person assigning
                if ($updates['assignee_id'] != null) {
                    $item->load('assignee');
                    $this->sendAssignmentNotification($item, $board, $user);
                }
            }
            
            // Check for mentions in description or repro_steps
            $mentionService = app(MentionService::class);
            $board->load('users');
            
            if (isset($updates['description']) && !empty($updates['description'])) {
                $this->sendMentionNotifications($mentionService, $board, $item, $updates['description'], 'description', $user);
            }
            
            if (isset($updates['repro_steps']) && !empty($updates['repro_steps'])) {
                $this->sendMentionNotifications($mentionService, $board, $item, $updates['repro_steps'], 'repro_steps', $user);
            }
        }

        if (request()->has('return_item')) {
            return redirect()->route('boards.show.item', ['board' => $board->id, 'item' => $item->number, 'view' => request('view', $board->view_type)])->with('success', 'Item updated.');
        }
        return redirect()->route('boards.show', ['board' => $board->id, 'view' => request('view', $board->view_type)])->with('success', 'Item updated.');
    }

    public function move(Request $request, Board $board, Item $item): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        if ($item->board_id !== $board->id) {
            abort(404);
        }
        $validated = $request->validate(['group_id' => 'required|exists:groups,id']);
        $item->update(['group_id' => $validated['group_id']]);
        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }
        return redirect()->route('boards.show', ['board' => $board, 'view' => 'kanban'])->with('success', 'Item moved.');
    }

    public function destroy(Request $request, Board $board, Item $item): RedirectResponse|JsonResponse
    {
        if ($item->board_id !== $board->id) {
            abort(404);
        }
        $item->delete();
        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }
        return redirect()->route('boards.show', ['board' => $board, 'view' => request('view', $board->view_type)])
            ->with('success', 'Item deleted.');
    }

    public function storeComment(Request $request, Board $board, Item $item): RedirectResponse
    {
        if ($item->board_id !== $board->id) {
            abort(404);
        }
        $validated = $request->validate(['body' => 'required|string|max:5000']);
        $comment = ItemComment::create([
            'item_id' => $item->id,
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);
        
        // Check for mentions in comment
        $mentionService = app(MentionService::class);
        $board->load('users');
        $this->sendMentionNotifications($mentionService, $board, $item, $validated['body'], 'comment', $request->user());
        
        return redirect()->route('boards.show.item', ['board' => $board->id, 'item' => $item->number, 'view' => request('view', $board->view_type)])
            ->with('success', 'Comment added.');
    }

    public function destroyComment(Request $request, Board $board, Item $item, ItemComment $comment): RedirectResponse
    {
        if ($item->board_id !== $board->id || $comment->item_id !== $item->id) {
            abort(404);
        }
        $comment->delete();
        return redirect()->route('boards.show.item', ['board' => $board->id, 'item' => $item->number, 'view' => request('view', $board->view_type)])
            ->with('success', 'Comment deleted.');
    }

    public function storeAttachment(Request $request, Board $board, Item $item): RedirectResponse
    {
        if ($item->board_id !== $board->id) {
            abort(404);
        }
        $request->validate(['image' => 'required|image|mimes:jpeg,png,gif,webp|max:10240']);
        $file = $request->file('image');
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $ext = 'jpg';
        }
        $name = Str::random(8) . '.' . $ext;
        $path = 'item-attachments/' . $item->id . '/' . $name;

        $optimized = $this->optimizeImage($file->getRealPath(), $ext);
        $content = $optimized ?? file_get_contents($file->getRealPath());

        Storage::disk('public')->put($path, $content);

        $attachments = $item->attachments ?? [];
        $attachments[] = $path;
        $item->update(['attachments' => $attachments]);
        return redirect()->route('boards.show.item', ['board' => $board->id, 'item' => $item->number, 'view' => request('view', $board->view_type)])
            ->with('success', 'Image added.');
    }

    /**
     * Resize and compress image so large files don't consume excessive storage.
     * Max dimension 1920px, JPEG/WebP quality 85%, PNG compression.
     */
    private function optimizeImage(string $filePath, string $ext): ?string
    {
        $maxDimension = 1920;
        $jpegQuality = 85;
        $pngCompression = 8;

        $image = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($filePath),
            'png' => @imagecreatefrompng($filePath),
            'gif' => @imagecreatefromgif($filePath),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filePath) : null,
            default => null,
        };
        if ($image === false || $image === null) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 1 || $height < 1) {
            imagedestroy($image);
            return null;
        }

        if ($width > $maxDimension || $height > $maxDimension) {
            $ratio = min($maxDimension / $width, $maxDimension / $height);
            $newWidth = (int) round($width * $ratio);
            $newHeight = (int) round($height * $ratio);
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            if ($resized === false) {
                imagedestroy($image);
                return null;
            }
            if ($ext === 'png' || $ext === 'gif') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
                imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
            }
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
            $width = $newWidth;
            $height = $newHeight;
        }

        ob_start();
        $success = match ($ext) {
            'jpg', 'jpeg' => imagejpeg($image, null, $jpegQuality),
            'png' => imagepng($image, null, $pngCompression),
            'gif' => imagegif($image, null),
            'webp' => function_exists('imagewebp') ? imagewebp($image, null, $jpegQuality) : imagejpeg($image, null, $jpegQuality),
            default => false,
        };
        $content = ob_get_clean();
        imagedestroy($image);

        return $success && $content !== false ? $content : null;
    }

    public function deleteAttachment(Request $request, Board $board, Item $item): RedirectResponse
    {
        if ($item->board_id !== $board->id) {
            abort(404);
        }
        $path = $request->input('path');
        $attachments = $item->attachments ?? [];
        if (! is_string($path) || ! in_array($path, $attachments, true)) {
            return redirect()->route('boards.show.item', ['board' => $board->id, 'item' => $item->number, 'view' => request('view', $board->view_type)])
                ->with('error', 'Invalid attachment.');
        }
        Storage::disk('public')->delete($path);
        $attachments = array_values(array_filter($attachments, fn ($p) => $p !== $path));
        $item->update(['attachments' => $attachments]);
        return redirect()->route('boards.show.item', ['board' => $board->id, 'item' => $item->number, 'view' => request('view', $board->view_type)])
            ->with('success', 'Image removed.');
    }

    /**
     * Send mention notifications to users
     */
    private function sendMentionNotifications(MentionService $mentionService, Board $board, Item $item, string $content, string $contentType, User $mentionedBy): void
    {
        // Check if Microsoft Graph credentials are configured
        if (!config('services.microsoft.client_id') || !config('services.microsoft.client_secret')) {
            return; // Microsoft Graph not configured
        }
        
        $mentionedUserIds = $mentionService->extractMentions($content, $board);
        
        if (empty($mentionedUserIds)) {
            return;
        }
        
        // Don't notify the person who made the mention
        $mentionedUserIds = array_filter($mentionedUserIds, fn($id) => $id !== $mentionedBy->id);
        
        if (empty($mentionedUserIds)) {
            return;
        }
        
        $mentionedUsers = User::whereIn('id', $mentionedUserIds)->get();
        
        // Send via Microsoft Graph API
        $this->sendViaMicrosoftGraph($mentionedUsers, $mentionedBy, $item, $board, $content, $contentType);
    }
    
    /**
     * Send emails via Microsoft Graph API
     */
    private function sendViaMicrosoftGraph($mentionedUsers, User $mentionedBy, Item $item, Board $board, string $content, string $contentType): void
    {
        $graphService = app(\App\Services\MicrosoftGraphMailService::class);
        
        // Render email HTML
        $preview = mb_substr(strip_tags($content), 0, 200);
        if (mb_strlen($content) > 200) {
            $preview .= '...';
        }
        
        $itemType = $item->isBug() ? 'Bug' : 'Task';
        $itemUrl = route('boards.show.item', [
            'board' => $board->id,
            'item' => $item->number,
            'view' => $board->view_type,
        ]);
        
        $htmlBody = view('emails.mention', [
            'mentionedUser' => (object)['name' => 'User'],
            'mentionedBy' => $mentionedBy,
            'item' => $item,
            'board' => $board,
            'preview' => $preview,
            'contentType' => $contentType,
            'itemUrl' => $itemUrl,
        ])->render();
        
        $subject = "You were mentioned in {$itemType} #{$item->number}";
        
        foreach ($mentionedUsers as $mentionedUser) {
            try {
                $success = $graphService->sendEmail(
                    $mentionedUser->email,
                    $subject,
                    $htmlBody
                );
                
                if (! $success) {
                    \Log::error("Failed to send mention notification via Microsoft Graph to: {$mentionedUser->email}");
                }
            } catch (\Exception $e) {
                \Log::error('Failed to send mention notification via Microsoft Graph: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Send assignment notification email
     */
    private function sendAssignmentNotification(Item $item, Board $board, User $assignedBy): void
    {
        // Check if Microsoft Graph credentials are configured
        if (!config('services.microsoft.client_id') || !config('services.microsoft.client_secret')) {
            return; // Microsoft Graph not configured
        }
        
        // Don't send email if assigning to self
        if ($item->assignee_id === $assignedBy->id) {
            return;
        }
        
        // Ensure assignee is loaded
        if (!$item->relationLoaded('assignee')) {
            $item->load('assignee');
        }
        
        if (!$item->assignee || !$item->assignee->email) {
            return;
        }
        
        $graphService = app(\App\Services\MicrosoftGraphMailService::class);
        
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
            $success = $graphService->sendEmail(
                $item->assignee->email,
                $subject,
                $htmlBody
            );
            
            if (! $success) {
                \Log::error("Failed to send assignment notification via Microsoft Graph to: {$item->assignee->email}");
            }
        } catch (\Exception $e) {
            \Log::error('Failed to send assignment notification via Microsoft Graph: ' . $e->getMessage());
        }
    }
    

}
