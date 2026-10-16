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
            $assigneeMatched = true;
            $warning = null;
            if (! empty($row['assigneeEmail'])) {
                $assignee = $this->findUserByEmail((string) $row['assigneeEmail']);
                $assigneeMatched = $assignee !== null;
                if (! $assigneeMatched) {
                    $warning = 'No user found for '.$row['assigneeEmail'].' (existing ticket left unchanged).';
                }
            }

            return $this->itemPayload($existing, $board, $externalKey, $assigneeMatched, $warning, created: false);
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

        $description = trim((string) ($row['description'] ?? ''));
        $relatedTo = isset($row['relatedTo']) ? trim((string) $row['relatedTo']) : '';
        $relatedColumn = $this->findRelatedColumn($board);

        $footerLines = [];
        if ($meetingDate !== null && trim($meetingDate) !== '') {
            $footerLines[] = 'Meeting: '.trim($meetingDate);
        }
        if ($relatedTo !== '' && $relatedColumn === null) {
            $footerLines[] = 'Related to: '.$relatedTo;
        }

        if ($footerLines !== []) {
            $footer = implode("\n", $footerLines);
            $description = $description === ''
                ? $footer
                : rtrim($description)."\n\n---\n".$footer;
        }

        $devTag = isset($row['devTag']) ? trim((string) $row['devTag']) : null;
        if ($devTag === '') {
            $devTag = null;
        }

        $item = $this->itemCreator->create($board, $creator, [
            'name' => $row['name'],
            'item_type' => $row['itemType'] ?? 'task',
            'priority' => $row['priority'] ?? 'medium',
            'due_at' => $row['dueAt'] ?? null,
            'assignee_id' => $assigneeId,
            'description' => $description !== '' ? $description : null,
            'dev_tag' => $devTag,
            'external_key' => $externalKey,
        ]);

        if ($relatedTo !== '' && $relatedColumn !== null) {
            ItemColumnValue::create([
                'item_id' => $item->id,
                'column_id' => $relatedColumn->id,
                'value' => ['text' => $relatedTo],
            ]);
        }

        return $this->itemPayload($item, $board, $externalKey, $assigneeMatched, $warning, created: true);
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

        return $payload;
    }
}
