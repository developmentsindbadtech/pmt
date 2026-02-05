<?php

namespace App\Mail;

use App\Models\Board;
use App\Models\Item;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MentionNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $mentionedUser,
        public User $mentionedBy,
        public Item $item,
        public Board $board,
        public string $content,
        public string $contentType // 'comment' or 'description' or 'repro_steps'
    ) {}

    public function envelope(): Envelope
    {
        $itemType = $this->item->isBug() ? 'Bug' : 'Task';
        
        return new Envelope(
            subject: "You were mentioned in {$itemType} #{$this->item->number}",
        );
    }

    public function content(): Content
    {
        $preview = mb_substr(strip_tags($this->content), 0, 200);
        if (mb_strlen($this->content) > 200) {
            $preview .= '...';
        }
        
        $itemUrl = route('boards.show.item', [
            'board' => $this->board->id,
            'item' => $this->item->number,
            'view' => $this->board->view_type,
        ]);
        
        return new Content(
            view: 'emails.mention',
            with: [
                'mentionedUser' => $this->mentionedUser,
                'mentionedBy' => $this->mentionedBy,
                'item' => $this->item,
                'board' => $this->board,
                'preview' => $preview,
                'contentType' => $this->contentType,
                'itemUrl' => $itemUrl,
            ],
        );
    }
}
