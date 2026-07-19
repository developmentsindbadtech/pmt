<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sheet extends Model
{
    protected $fillable = [
        'name',
        'description',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** Sheets the user owns or is assigned to (personal list — not “all sheets”). */
    public function scopeVisibleTo($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('created_by', $user->id)
                ->orWhereHas('users', fn ($u) => $u->where('users.id', $user->id));
        });
    }

    public function columns(): HasMany
    {
        return $this->hasMany(SheetColumn::class)->orderBy('position');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(SheetRow::class)->orderBy('position');
    }

    /** Available column types: slug => label. */
    public static function columnTypes(): array
    {
        return [
            'text' => 'Text',
            'number' => 'Number',
            'date' => 'Date',
            'status' => 'Status',
            'person' => 'Person',
            'checkbox' => 'Checkbox',
            'link' => 'Link',
        ];
    }
}
