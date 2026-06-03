<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SheetColumn extends Model
{
    protected $fillable = [
        'sheet_id',
        'name',
        'type',
        'options',
        'position',
    ];

    protected $casts = [
        'options' => 'array',
        'position' => 'integer',
    ];

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(Sheet::class);
    }
}
