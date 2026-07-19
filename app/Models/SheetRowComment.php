<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SheetRowComment extends Model
{
    protected $fillable = [
        'sheet_row_id',
        'user_id',
        'body',
    ];

    public function row(): BelongsTo
    {
        return $this->belongsTo(SheetRow::class, 'sheet_row_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
