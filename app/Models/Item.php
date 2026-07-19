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
            $dir = 'item-attachments/'.$item->id;
            if (Storage::disk('public')->exists($dir)) {
                Storage::disk('public')->deleteDirectory($dir);
            }
        });
    }

    protected $fillable = [
        'board_id',
        'parent_id',
        'number',
        'name',
        'item_type',
        'priority',
        'severity',
        'description',
        'repro_steps',
        'attachments',
        'group_id',
        'position',
        'created_by',
        'assignee_id',
        'due_at',
        'dev_tag',
        'archived_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'number' => 'integer',
        'attachments' => 'array',
        'due_at' => 'date',
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
    }

    public function unarchive(): void
    {
        if ($this->archived_at !== null) {
            $this->forceFill(['archived_at' => null])->save();
        }
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Item::class, 'parent_id')->orderBy('position');
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

    public function isOverdue(): bool
    {
        return $this->due_at && $this->due_at->isPast();
    }

    public static function priorityOptions(): array
    {
        return ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];
    }

    public static function severityOptions(): array
    {
        return ['minor' => 'Minor', 'major' => 'Major', 'critical' => 'Critical', 'blocker' => 'Blocker'];
    }

    /** Predefined workstream tags (stored slug → display with #). */
    public static function devTagOptions(): array
    {
        return [
            'backend' => '#backend',
            'frontend' => '#frontend',
            'data' => '#data',
            'devops' => '#devops',
            'api' => '#api',
            'ui' => '#ui',
            'mobile' => '#mobile',
            'security' => '#security',
            'performance' => '#performance',
            'docs' => '#docs',
            'testing' => '#testing',
            'accessibility' => '#a11y',
        ];
    }

    public static function devTagLabel(?string $slug): ?string
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return static::devTagOptions()[$slug] ?? ('#'.$slug);
    }

    /** Human label for history / CSV. */
    public static function formatParentRef(?self $row): ?string
    {
        if ($row === null) {
            return null;
        }

        return '#'.$row->number.' '.mb_substr($row->name, 0, 80);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Item>  $sameBoardItems  Items on the board (id, parent_id, number, name).
     * @return \Illuminate\Support\Collection<int, Item>
     */
    public static function filterValidParentCandidates(self $item, \Illuminate\Support\Collection $sameBoardItems): \Illuminate\Support\Collection
    {
        $byId = $sameBoardItems->keyBy('id');
        $itemId = $item->id;

        $createsCycle = function (int $candidateParentId) use ($byId, $itemId): bool {
            $current = $candidateParentId;
            $guard = 0;
            while ($current && $guard < 500) {
                if ($current === $itemId) {
                    return true;
                }
                $row = $byId->get($current);
                if (! $row || ! $row->parent_id) {
                    break;
                }
                $current = (int) $row->parent_id;
                $guard++;
            }

            return false;
        };

        return $sameBoardItems
            ->filter(fn (self $row) => $row->id !== $itemId)
            ->filter(fn (self $row) => ! $createsCycle((int) $row->id))
            ->sortBy('number')
            ->values();
    }
}
