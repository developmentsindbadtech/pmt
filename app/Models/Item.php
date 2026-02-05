<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Item extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (Item $item): void {
            $attachments = $item->attachments ?? [];
            foreach ($attachments as $path) {
                if (is_string($path)) {
                    Storage::disk('public')->delete($path);
                }
            }
            $dir = 'item-attachments/' . $item->id;
            if (Storage::disk('public')->exists($dir)) {
                Storage::disk('public')->deleteDirectory($dir);
            }
        });
    }

    protected $fillable = [
        'board_id',
        'number',
        'name',
        'item_type',
        'description',
        'repro_steps',
        'attachments',
        'group_id',
        'position',
        'created_by',
        'assignee_id',
    ];

    protected $casts = [
        'position' => 'integer',
        'number' => 'integer',
        'attachments' => 'array',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ItemComment::class)->orderBy('created_at');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ItemActivity::class)->orderByDesc('created_at');
    }

    public function itemColumnValues(): HasMany
    {
        return $this->hasMany(ItemColumnValue::class);
    }

    public function isTask(): bool
    {
        return $this->item_type === 'task';
    }

    public function isBug(): bool
    {
        return $this->item_type === 'bug';
    }
}
