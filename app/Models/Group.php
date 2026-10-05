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
        'finished',
    ];

    protected $casts = [
        'position' => 'integer',
        'wip_limit' => 'integer',
        'finished' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Group $group) {
            if (! array_key_exists('finished', $group->getAttributes())) {
                $group->finished = static::isClosedColumn($group->name);
            }
        });
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'group_id')->orderBy('position');
    }

    /**
     * Board SOP: only the Closed column is done.
     */
    public static function isClosedColumn(?string $name): bool
    {
        return mb_strtolower(trim((string) $name)) === 'closed';
    }

    /**
     * Sheet rows use Done. Board tickets use Closed. Both mean the work is finished.
     */
    public static function isDoneName(?string $name): bool
    {
        $label = mb_strtolower(trim((string) $name));

        return $label === 'closed' || $label === 'done';
    }

    public function isDone(): bool
    {
        return static::isClosedColumn($this->name);
    }
}
