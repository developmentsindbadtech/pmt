<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    protected $fillable = [
        'board_id',
        'name',
        'position',
        'wip_limit',
    ];

    protected $casts = [
        'position' => 'integer',
        'wip_limit' => 'integer',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'group_id')->orderBy('position');
    }

    /**
     * Terminal / "done" columns: Closed, Done, Complete, etc.
     */
    public static function isDoneName(?string $name): bool
    {
        if ($name === null || trim($name) === '') {
            return false;
        }
        $l = mb_strtolower(trim($name));

        return str_contains($l, 'done')
            || str_contains($l, 'complete')
            || str_contains($l, 'closed');
    }

    public function isDone(): bool
    {
        return static::isDoneName($this->name);
    }
}
