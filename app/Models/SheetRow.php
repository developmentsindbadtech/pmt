<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SheetRow extends Model
{
    protected $fillable = [
        'sheet_id',
        'parent_id',
        'position',
        'values',
    ];

    protected $casts = [
        'values' => 'array',
        'position' => 'integer',
    ];

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(Sheet::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(SheetRow::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(SheetRow::class, 'parent_id')->orderBy('position');
    }
}
