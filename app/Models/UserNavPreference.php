<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNavPreference extends Model
{
    protected $fillable = [
        'user_id',
        'subject_type',
        'subject_id',
        'pinned',
        'hidden',
    ];

    protected $casts = [
        'pinned' => 'boolean',
        'hidden' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
