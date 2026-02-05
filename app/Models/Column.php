<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Column extends Model
{
    protected $fillable = [
        'board_id',
        'name',
        'type',
        'position',
        'settings',
    ];

    protected $casts = [
        'position' => 'integer',
        'settings' => 'array',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function itemColumnValues(): HasMany
    {
        return $this->hasMany(ItemColumnValue::class, 'column_id');
    }
}
