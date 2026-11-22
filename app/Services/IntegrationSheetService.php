<?php

namespace App\Services;

use App\Models\Sheet;
use App\Models\SheetColumn;
use App\Models\SheetRow;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class IntegrationSheetService
{
    /**
     * Sheets this person may open. Admins can open any sheet. Everyone else
     * only gets sheets they own or are assigned to.
     *
     * @return list<array{id: int, name: string}>
     */
    public function listFor(User $viewer): array
    {
        $query = $viewer->isAdmin()
            ? Sheet::query()
            : Sheet::query()->visibleTo($viewer);

        return $query
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Sheet $sheet) => [
                'id' => $sheet->id,
                'name' => $sheet->name,
            ])
            ->values()
            ->all();
    }

    public function canWrite(User $viewer, Sheet $sheet): bool
    {
        if ($viewer->isAdmin()) {
            return true;
        }
        if ((int) $sheet->created_by === (int) $viewer->id) {
            return true;
        }

        return $sheet->users()->where('users.id', $viewer->id)->exists();
    }

    /**
     * Append rows using the sheet's own columns. Board fields such as dev tag
     * and task/bug type are not written here.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{sheet: array{id: int, name: string}, items: list<array<string, mixed>>}
     */
    public function appendRows(Sheet $sheet, ?string $meetingDate, array $items): array
    {
        $columns = $sheet->columns()->orderBy('position')->get();
        $titleCol = $this->columnNamed($columns, 'title', 'text')
            ?? $columns->first(fn (SheetColumn $col) => $col->type === 'text');
        $statusCol = $this->columnNamed($columns, 'status', 'status')
            ?? $columns->first(fn (SheetColumn $col) => $col->type === 'status' && mb_strtolower($col->name) !== 'priority');
        $ownerCol = $this->columnNamed($columns, 'owner', 'person')
            ?? $columns->first(fn (SheetColumn $col) => $col->type === 'person');
        $dueCol = $this->columnNamed($columns, 'due', 'date')
            ?? $columns->first(fn (SheetColumn $col) => $col->type === 'date');
        $priorityCol = $this->columnNamed($columns, 'priority', 'status');

        $results = [];
        foreach ($items as $row) {
            $results[] = $this->appendOne(
                $sheet,
                $meetingDate,
                $row,
                $titleCol,
                $statusCol,
                $ownerCol,
                $dueCol,
                $priorityCol,
            );
        }

        return [
            'sheet' => [
                'id' => $sheet->id,
                'name' => $sheet->name,
            ],
            'items' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function appendOne(
        Sheet $sheet,
        ?string $meetingDate,
        array $row,
        ?SheetColumn $titleCol,
        ?SheetColumn $statusCol,
        ?SheetColumn $ownerCol,
        ?SheetColumn $dueCol,
        ?SheetColumn $priorityCol,
    ): array {
        $externalKey = (string) $row['externalKey'];
        $existing = $this->findByFilingKey($sheet, $externalKey);
        if ($existing) {
            return $this->payload($sheet, $existing, $externalKey, true, false, null);
        }

        $warnings = [];
        $values = [];
        $name = trim((string) ($row['name'] ?? ''));
        if ($titleCol && $name !== '') {
            $values[(string) $titleCol->id] = mb_substr($name, 0, 2000);
        } elseif ($name !== '') {
            $warnings[] = 'This sheet has no Title column, so the task name is only in the row notes.';
        }

        if ($statusCol) {
            $status = $this->openStatus($statusCol);
            if ($status !== null) {
                $values[(string) $statusCol->id] = $status;
            }
        }

        $assigneeMatched = true;
        $assigneeEmail = null;
        if ($ownerCol && ! empty($row['assigneeEmail'])) {
            $assigneeEmail = (string) $row['assigneeEmail'];
            $assignee = User::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($assigneeEmail))])
                ->first();
            if ($assignee) {
                $values[(string) $ownerCol->id] = $assignee->id;
            } else {
                $assigneeMatched = false;
                $warnings[] = 'No PMT user found for '.$assigneeEmail.', so the row was left unassigned.';
            }
        } elseif (! empty($row['assigneeEmail']) && ! $ownerCol) {
            $assigneeMatched = false;
            $warnings[] = 'This sheet has no Owner column, so the assignee was left in the notes only.';
        }

        if ($dueCol && ! empty($row['dueAt'])) {
            try {
                $values[(string) $dueCol->id] = Carbon::parse((string) $row['dueAt'])->toDateString();
            } catch (\Throwable) {
                $warnings[] = 'The due date could not be read, so the Due cell was left blank.';
            }
        }

        if ($priorityCol) {
            $label = $this->priorityLabel((string) ($row['priority'] ?? 'medium'));
            $option = $this->matchOption($priorityCol, $label);
            if ($option !== null) {
                $values[(string) $priorityCol->id] = $option;
            } else {
                $warnings[] = 'This sheet has no "'.$label.'" priority, so that cell was left blank.';
            }
        }

        $position = (int) SheetRow::query()
            ->where('sheet_id', $sheet->id)
            ->whereNull('parent_id')
            ->max('position') + 1;

        $created = SheetRow::create([
            'sheet_id' => $sheet->id,
            'parent_id' => null,
            'position' => $position,
            'values' => $values,
            'description' => $this->notes($row, $meetingDate, $externalKey, $titleCol === null ? $name : ''),
        ]);

        return $this->payload(
            $sheet,
            $created,
            $externalKey,
            $assigneeMatched,
            true,
            $warnings === [] ? null : implode(' ', $warnings),
            $assigneeEmail,
        );
    }

    private function findByFilingKey(Sheet $sheet, string $externalKey): ?SheetRow
    {
        $needle = 'Filing: '.addcslashes($externalKey, '%_\\');

        return SheetRow::query()
            ->where('sheet_id', $sheet->id)
            ->where('description', 'like', $needle.'%')
            ->first();
    }

    /**
     * @param  Collection<int, SheetColumn>  $columns
     */
    private function columnNamed(Collection $columns, string $name, string $type): ?SheetColumn
    {
        return $columns->first(
            fn (SheetColumn $col) => $col->type === $type && mb_strtolower(trim($col->name)) === $name,
        );
    }

    private function matchOption(SheetColumn $column, string $wanted): ?string
    {
        foreach ($column->options ?? [] as $option) {
            $label = trim((string) $option);
            if ($label !== '' && mb_strtolower($label) === mb_strtolower($wanted)) {
                return $label;
            }
        }

        return null;
    }

    private function openStatus(SheetColumn $column): ?string
    {
        foreach (['To Do', 'New', 'Not Started', 'Open', 'Backlog'] as $candidate) {
            $match = $this->matchOption($column, $candidate);
            if ($match !== null && ! $column->statusIsFinished($match)) {
                return $match;
            }
        }

        foreach ($column->options ?? [] as $option) {
            $label = trim((string) $option);
            if ($label !== '' && ! $column->statusIsFinished($label)) {
                return $label;
            }
        }

        return null;
    }

    private function priorityLabel(string $priority): string
    {
        return match (mb_strtolower(trim($priority))) {
            'high' => 'High',
            'low' => 'Low',
            default => 'Medium',
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function notes(array $row, ?string $meetingDate, string $externalKey, string $fallbackTitle): string
    {
        $parts = ['Filing: '.$externalKey];
        if ($fallbackTitle !== '') {
            $parts[] = $fallbackTitle;
        }
        $description = trim((string) ($row['description'] ?? ''));
        if ($description !== '') {
            $parts[] = $description;
        }
        $related = trim((string) ($row['relatedTo'] ?? ''));
        if ($related !== '' && ! preg_match('/^(none|n\/a|na|tbd|-)$/i', $related)) {
            $parts[] = 'Related to: '.$related;
        }
        if ($meetingDate !== null && trim($meetingDate) !== '') {
            $parts[] = 'Meeting: '.trim($meetingDate);
        }

        return implode("\n\n", $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        Sheet $sheet,
        SheetRow $row,
        string $externalKey,
        bool $assigneeMatched,
        bool $created,
        ?string $warning,
        ?string $assigneeEmail = null,
    ): array {
        $payload = [
            'externalKey' => $externalKey,
            'id' => $row->id,
            'number' => $row->id,
            'name' => 'Row '.$row->id,
            'url' => url('/sheets/'.$sheet->id),
            'assigneeMatched' => $assigneeMatched,
            'created' => $created,
        ];
        if ($warning !== null && $warning !== '') {
            $payload['warning'] = $warning;
        }
        if ($assigneeEmail !== null && $assigneeEmail !== '') {
            $payload['assigneeEmail'] = $assigneeEmail;
        }

        return $payload;
    }
}
