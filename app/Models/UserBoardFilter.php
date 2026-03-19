<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBoardFilter extends Model
{
    protected $fillable = [
        'user_id',
        'board_id',
        'assignee_id',
        'filter_unassigned',
        'item_type',
        'filter_group_ids',
    ];

    protected $casts = [
        'filter_unassigned' => 'boolean',
        'filter_group_ids' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
