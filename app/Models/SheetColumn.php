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
        'settings',
    ];

    protected $casts = [
        'options' => 'array',
        'position' => 'integer',
        'settings' => 'array',
    ];

    public function statusIsFinished(?string $label): bool
    {
        if ($label === null || trim($label) === '') {
            return false;
        }
        return Group::isDoneName($label);
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(Sheet::class);
    }
}
