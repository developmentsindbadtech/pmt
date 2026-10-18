<?php

namespace App\Services;

use App\Models\Board;
use App\Models\Item;
use App\Models\ItemActivity;
use App\Models\ItemColumnValue;
use App\Models\User;

/**
 * Creates board items the same way the browser ItemController::store path does,
 * with optional fields used by the transcript integration API.
 */
class BoardItemCreator
{
    public function __construct(
        private ItemAssignmentNotifier $assignmentNotifier,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     item_type?: string,
     *     priority?: string,
     *     severity?: string|null,
     *     due_at?: string|null,
     *     assignee_id?: int|null,
     *     description?: string|null,
     *     repro_steps?: string|null,
     *     dev_tag?: string|null,
     *     external_key?: string|null,
     *     group_id?: int|null,
     * }  $attributes
     */
    public function create(Board $board, User $creator, array $attributes): Item
    {
        $groupId = array_key_exists('group_id', $attributes) && $attributes['group_id'] !== null
            ? $attributes['group_id']
            : $board->groups()->orderBy('position')->first()?->id;

        $maxPosition = $board->items()->when($groupId !== null, function ($q) use ($groupId) {
            $q->where('group_id', $groupId);
        })->max('position') ?? -1;

        $nextNumber = ((int) $board->items()->max('number')) + 1;
        $itemType = $attributes['item_type'] ?? 'task';

        $item = Item::create([
            'board_id' => $board->id,
            'number' => $nextNumber,
            'name' => $attributes['name'],
            'item_type' => $itemType,
            'priority' => $attributes['priority'] ?? 'medium',
            'severity' => $itemType === 'bug' ? ($attributes['severity'] ?? 'major') : null,
            'due_at' => $attributes['due_at'] ?? null,
            'group_id' => $groupId,
            'position' => $maxPosition + 1,
            'created_by' => $creator->id,
            'assignee_id' => $attributes['assignee_id'] ?? null,
            'description' => $attributes['description'] ?? null,
            'repro_steps' => $itemType === 'bug' ? ($attributes['repro_steps'] ?? null) : null,
            'dev_tag' => $attributes['dev_tag'] ?? null,
            'external_key' => $attributes['external_key'] ?? null,
        ]);

        ItemActivity::create([
            'item_id' => $item->id,
            'user_id' => $creator->id,
            'type' => 'created',
            'field' => null,
            'old_value' => null,
            'new_value' => null,
        ]);

        if ($item->assignee_id && $item->assignee_id != $creator->id) {
            $this->assignmentNotifier->notify($item, $board, $creator);
        }

        $statusColumn = $board->columns()->where('type', 'status')->orderBy('position')->first();
        if ($statusColumn) {
            $defaultStatus = $statusColumn->settings['options'][0] ?? 'To Do';
            ItemColumnValue::create([
                'item_id' => $item->id,
                'column_id' => $statusColumn->id,
                'value' => ['text' => $defaultStatus],
            ]);
        }

        foreach ($board->columns()->where('type', '!=', 'status')->get() as $col) {
            if ($col->name === 'Name') {
                ItemColumnValue::create([
                    'item_id' => $item->id,
                    'column_id' => $col->id,
                    'value' => ['text' => $item->name],
                ]);
            }
        }

        return $item;
    }
}
