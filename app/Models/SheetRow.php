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
        'description',
        'archived_at',
    ];

    protected $casts = [
        'values' => 'array',
        'position' => 'integer',
        'archived_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function archive(): void
    {
        if ($this->archived_at === null) {
            $this->forceFill(['archived_at' => now()])->save();
        }
        // Archive subtasks with the parent.
        static::where('parent_id', $this->id)->whereNull('archived_at')->update(['archived_at' => now()]);
    }

    public function unarchive(): void
    {
        if ($this->archived_at !== null) {
            $this->forceFill(['archived_at' => null])->save();
        }
        static::where('parent_id', $this->id)->whereNotNull('archived_at')->update(['archived_at' => null]);
    }

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

    public function comments(): HasMany
    {
        return $this->hasMany(SheetRowComment::class)->orderBy('created_at');
    }
}
