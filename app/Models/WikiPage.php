<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WikiPage extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'body',
        'created_by',
        'updated_by',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function canDelete(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->is_admin || (int) $this->created_by === (int) $user->id;
    }

    public function html(): string
    {
        $body = trim((string) $this->body);
        if ($body === '') {
            return '';
        }

        return Str::markdown($body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug(Str::limit($title, 80, '')) ?: 'page';
        if (in_array($base, ['new', 'edit', 'create'], true)) {
            $base .= '-page';
        }

        $slug = $base;
        $n = 2;
        while (static::query()
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$n;
            $n++;
        }

        return $slug;
    }
}
