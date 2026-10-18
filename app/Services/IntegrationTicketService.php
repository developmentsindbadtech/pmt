<?php

namespace App\Services;

use App\Models\Board;
use App\Models\Column;
use App\Models\Item;
use App\Models\ItemColumnValue;
use App\Models\User;

class IntegrationTicketService
{
    public function __construct(
        private BoardItemCreator $itemCreator,
        private ItemAssignmentNotifier $assignmentNotifier,
    ) {}

    /**
     * Resolve a board by case-insensitive exact name.
     *
     * @return array{status: int, payload: array}
     */
    public function resolveBoard(string $boardName): array
    {
        $matches = Board::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($boardName))])
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($matches->isEmpty()) {
            return [
                'status' => 404,
                'payload' => [
                    'message' => 'Board not found.',
                    'boardName' => $boardName,
                ],
            ];
        }

        if ($matches->count() > 1) {
            return [
                'status' => 409,
                'payload' => [
                    'message' => 'Multiple boards match that name.',
                    'boardName' => $boardName,
                    'boards' => $matches->map(fn (Board $b) => [
                        'id' => $b->id,
                        'name' => $b->name,
                    ])->values()->all(),
                ],
            ];
        }

        return [
            'status' => 200,
            'payload' => ['board' => $matches->first()],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{board: array{id: int, name: string}, items: list<array<string, mixed>>}
     */
    public function upsertTickets(Board $board, User $creator, ?string $meetingDate, array $items): array
    {
        $results = [];

        foreach ($items as $row) {
            $results[] = $this->upsertOne($board, $creator, $meetingDate, $row);
        }

        $this->linkBatchRelations($board, $items, $results);

        return [
            'board' => [
                'id' => $board->id,
                'name' => $board->name,
            ],
            'items' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function upsertOne(Board $board, User $creator, ?string $meetingDate, array $row): array
    {
        $externalKey = (string) $row['externalKey'];

        $existing = Item::query()->where('external_key', $externalKey)->first();
        if ($existing) {
            return $this->fillMissing($existing, $board, $creator, $meetingDate, $row, $externalKey);
        }

        $assigneeId = null;
        $assigneeMatched = true;
        $warning = null;
        if (! empty($row['assigneeEmail'])) {
            $assignee = $this->findUserByEmail((string) $row['assigneeEmail']);
            if ($assignee) {
                $assigneeId = $assignee->id;
            } else {
                $assigneeMatched = false;
                $warning = 'No user found for '.$row['assigneeEmail'].'; ticket created unassigned.';
            }
        }

        $description = $this->composeBody($row, $meetingDate);
        $itemType = $row['itemType'] ?? 'task';

        $item = $this->itemCreator->create($board, $creator, [
            'name' => $row['name'],
            'item_type' => $itemType,
            'priority' => $row['priority'] ?? 'medium',
            'due_at' => $row['dueAt'] ?? null,
            'assignee_id' => $assigneeId,
            'description' => $description !== '' ? $description : null,
            'repro_steps' => $itemType === 'bug' && $description !== '' ? $description : null,
            'dev_tag' => $this->normalizeDevTag($row['devTag'] ?? null),
            'external_key' => $externalKey,
        ]);

        return $this->itemPayload($item, $board, $externalKey, $assigneeMatched, $warning, created: true, relatedLabel: null);
    }

    /**
     * Fill blank fields on a ticket that was already created. Existing text,
     * assignee, due date, tag, and relation are left as they are.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function fillMissing(Item $existing, Board $board, User $creator, ?string $meetingDate, array $row, string $externalKey): array
    {
        $assigneeMatched = true;
        $warning = null;
        $filled = [];

        if (! empty($row['assigneeEmail'])) {
            $assignee = $this->findUserByEmail((string) $row['assigneeEmail']);
            $assigneeMatched = $assignee !== null;
            if ($assignee && $existing->assignee_id === null) {
                $existing->forceFill(['assignee_id' => $assignee->id])->save();
                $this->assignmentNotifier->notify($existing, $board, $creator);
                $filled[] = 'assignee';
            } elseif (! $assignee) {
                $warning = 'No user found for '.$row['assigneeEmail'].' (existing ticket left unchanged).';
            }
        }

        $updates = [];
        $body = $this->composeBody($row, $meetingDate);
        if ($existing->item_type === 'bug') {
            if ($this->isBlank($existing->repro_steps)) {
                $stored = $this->isGeneratedNote($existing->description) ? '' : trim((string) $existing->description);
                $repro = $body !== '' ? $body : $stored;
                if ($repro !== '') {
                    $updates['repro_steps'] = $repro;
                    $filled[] = 'repro steps';
                }
            }
            if ($this->isGeneratedNote($existing->description) && $body !== '') {
                $updates['description'] = $body;
                $filled[] = 'description';
            }
        } elseif ($this->isGeneratedNote($existing->description) && $body !== '') {
            $updates['description'] = $body;
            $filled[] = 'description';
        }

        $incomingTag = $this->normalizeDevTag($row['devTag'] ?? null);
        $currentTag = $existing->dev_tag;
        if ($this->isBlank($currentTag)) {
            if ($incomingTag !== null) {
                $updates['dev_tag'] = $incomingTag;
                $filled[] = 'dev tag';
            }
        } else {
            $normalizedCurrent = $this->normalizeDevTag($currentTag);
            if (
                $normalizedCurrent !== null
                && $normalizedCurrent !== $currentTag
                && array_key_exists($normalizedCurrent, Item::devTagOptions())
            ) {
                $updates['dev_tag'] = $normalizedCurrent;
                $filled[] = 'dev tag';
            }
        }

        if ($existing->due_at === null && ! empty($row['dueAt'])) {
            $updates['due_at'] = $row['dueAt'];
            $filled[] = 'due date';
        }

        if ($updates !== []) {
            $existing->forceFill($updates)->save();
        }

        return $this->itemPayload($existing, $board, $externalKey, $assigneeMatched, $warning, created: false, filled: $filled);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function composeBody(array $row, ?string $meetingDate): string
    {
        $description = trim((string) ($row['description'] ?? ''));
        if ($meetingDate !== null && trim($meetingDate) !== '') {
            $footer = 'Meeting: '.trim($meetingDate);
            $description = $description === ''
                ? $footer
                : rtrim($description)."\n\n---\n".$footer;
        }

        return $description;
    }

    private function isBlank(mixed $value): bool
    {
        return trim((string) $value) === '';
    }

    /** True when the text is empty or only the notes this integration adds itself. */
    private function isGeneratedNote(mixed $value): bool
    {
        $text = trim((string) $value);
        if ($text === '') {
            return true;
        }

        $lines = preg_split('/\R/', $text) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line === '---') {
                continue;
            }
            if (preg_match('/^(Related to:|Mentioned with:|Meeting:)/', $line) === 1) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function normalizeDevTag(mixed $tag): ?string
    {
        $value = trim((string) $tag);
        if ($value === '' || preg_match('/^(none|n\/a|na|-)$/i', $value) === 1) {
            return null;
        }

        $options = Item::devTagOptions();
        if (array_key_exists($value, $options)) {
            return $value;
        }

        $stripped = ltrim($value, '#');
        if (array_key_exists($stripped, $options)) {
            return $stripped;
        }

        foreach ($options as $slug => $label) {
            if (strcasecmp($label, $value) === 0 || strcasecmp($slug, $stripped) === 0) {
                return $slug;
            }
        }

        return $value;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $results
     */
    private function linkBatchRelations(Board $board, array $rows, array &$results): void
    {
        foreach ($rows as $index => $row) {
            $created = ($results[$index]['created'] ?? false) === true;
            $item = Item::query()->find($results[$index]['id'] ?? null);
            if (! $item instanceof Item) {
                continue;
            }
            if (! $created && $item->parent_id !== null) {
                continue;
            }

            $relatedTo = trim((string) ($row['relatedTo'] ?? ''));
            $match = $this->findRelatedItem($board, $item, $relatedTo);
            if (! $created && ! $match instanceof Item) {
                continue;
            }

            $label = 'None';
            if ($match instanceof Item) {
                $item->forceFill(['parent_id' => $match->id])->save();
                $label = 'PMT #'.$match->number.' '.$match->name;
                if (! $created) {
                    $filled = is_array($results[$index]['filled'] ?? null) ? $results[$index]['filled'] : [];
                    $filled[] = 'related to';
                    $results[$index]['filled'] = array_values($filled);
                }
            }

            $this->storeRelatedLabel($board, $item, $label, $relatedTo, fillOnly: ! $created);
            $results[$index]['relatedLabel'] = $label;
        }
    }

    private function isBlankRelation(string $relatedTo): bool
    {
        return $relatedTo === '' || preg_match('/^(none|n\/a|na|tbd|-)$/i', $relatedTo) === 1;
    }

    private function findRelatedItem(Board $board, Item $item, string $relatedTo): ?Item
    {
        if ($this->isBlankRelation($relatedTo)) {
            return null;
        }

        $matches = Item::query()
            ->where('board_id', $board->id)
            ->where('id', '!=', $item->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($relatedTo)])
            ->get();

        if ($matches->count() !== 1) {
            return null;
        }

        $match = $matches->first();
        if (! $match instanceof Item || $match->parent_id === $item->id) {
            return null;
        }

        return $match;
    }

    private function storeRelatedLabel(Board $board, Item $item, string $label, string $original, bool $fillOnly = false): void
    {
        $column = $this->findRelatedColumn($board);
        if ($column instanceof Column) {
            $current = ItemColumnValue::query()
                ->where('item_id', $item->id)
                ->where('column_id', $column->id)
                ->first();
            $currentText = is_array($current?->value) ? trim((string) ($current->value['text'] ?? '')) : '';
            if ($fillOnly && $currentText !== '') {
                return;
            }

            ItemColumnValue::query()->updateOrCreate(
                [
                    'item_id' => $item->id,
                    'column_id' => $column->id,
                ],
                ['value' => ['text' => $label]],
            );

            return;
        }

        if ($fillOnly && ! $this->isBlank($item->description)) {
            return;
        }

        $lines = ['Related to: '.$label];
        if ($label === 'None' && ! $this->isBlankRelation($original)) {
            $lines[] = 'Mentioned with: '.$original;
        }
        $footer = implode("\n", $lines);
        $description = trim((string) $item->description);
        $item->forceFill([
            'description' => $description === '' ? $footer : $description."\n".$footer,
        ])->save();
    }

    private function findUserByEmail(string $email): ?User
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->first();
    }

    private function findRelatedColumn(Board $board): ?Column
    {
        return $board->columns
            ->first(function (Column $col) {
                $name = mb_strtolower(trim($col->name));

                return in_array($name, ['related to', 'related', 'relatedto'], true);
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function itemPayload(
        Item $item,
        Board $board,
        string $externalKey,
        bool $assigneeMatched,
        ?string $warning,
        bool $created,
        ?string $relatedLabel = null,
        array $filled = [],
    ): array {
        $payload = [
            'externalKey' => $externalKey,
            'id' => $item->id,
            'number' => $item->number,
            'url' => url('/boards/'.$board->id.'/ticket/'.$item->number),
            'assigneeMatched' => $assigneeMatched,
            'created' => $created,
        ];

        if ($warning !== null) {
            $payload['warning'] = $warning;
        }
        if ($relatedLabel !== null) {
            $payload['relatedLabel'] = $relatedLabel;
        }
        if ($filled !== []) {
            $payload['filled'] = array_values($filled);
        }

        return $payload;
    }
}
