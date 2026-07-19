<?php

/**
 * One-off script: php database/seeders/seed_sheet_samples.php
 * Seeds demo rows into every sheet (or creates a demo sheet if none exist).
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Sheet;
use App\Models\SheetColumn;
use App\Models\SheetRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$admin = User::query()->where('is_admin', true)->first()
    ?? User::query()->first();

if (! $admin) {
    fwrite(STDERR, "No users found. Sign in once first.\n");
    exit(1);
}

$users = User::query()->orderBy('id')->get();
$ownerIds = $users->pluck('id')->values()->all();
$pickOwner = function (int $i) use ($ownerIds, $admin) {
    if (empty($ownerIds)) {
        return $admin->id;
    }

    return $ownerIds[$i % count($ownerIds)];
};

DB::transaction(function () use ($admin, $pickOwner) {
    $sheets = Sheet::query()->with('columns')->get();

    if ($sheets->isEmpty()) {
        $sheet = Sheet::create([
            'name' => 'Product Launch',
            'description' => 'Sample PM sheet with demo data',
            'created_by' => $admin->id,
        ]);
        $sheet->users()->syncWithoutDetaching([$admin->id]);

        $defs = [
            ['name' => 'Title', 'type' => 'text', 'options' => null, 'position' => 0],
            ['name' => 'Status', 'type' => 'status', 'options' => ['To Do', 'In Progress', 'Stuck', 'Done'], 'position' => 1],
            ['name' => 'Owner', 'type' => 'person', 'options' => null, 'position' => 2],
            ['name' => 'Due', 'type' => 'date', 'options' => null, 'position' => 3],
            ['name' => 'Priority', 'type' => 'status', 'options' => ['Critical', 'High', 'Medium', 'Low'], 'position' => 4],
        ];
        foreach ($defs as $def) {
            SheetColumn::create(array_merge($def, ['sheet_id' => $sheet->id]));
        }
        $sheets = Sheet::query()->with('columns')->get();
        echo "Created demo sheet: {$sheet->name}\n";
    }

    foreach ($sheets as $sheet) {
        // Ensure PM columns exist (same as UI "PM columns" button).
        $byName = $sheet->columns->keyBy(fn ($c) => mb_strtolower($c->name));
        $position = (int) $sheet->columns->max('position');
        foreach ([
            ['name' => 'Owner', 'type' => 'person', 'options' => null],
            ['name' => 'Due', 'type' => 'date', 'options' => null],
            ['name' => 'Priority', 'type' => 'status', 'options' => ['Critical', 'High', 'Medium', 'Low']],
            ['name' => 'Status', 'type' => 'status', 'options' => ['To Do', 'In Progress', 'Stuck', 'Done']],
            ['name' => 'Title', 'type' => 'text', 'options' => null],
        ] as $def) {
            if ($byName->has(mb_strtolower($def['name']))) {
                continue;
            }
            $position++;
            $col = SheetColumn::create([
                'sheet_id' => $sheet->id,
                'name' => $def['name'],
                'type' => $def['type'],
                'options' => $def['options'],
                'position' => $position,
            ]);
            $sheet->columns->push($col);
            $byName = $sheet->columns->keyBy(fn ($c) => mb_strtolower($c->name));
            echo "  + column {$def['name']} on {$sheet->name}\n";
        }

        $titleCol = $sheet->columns->first(fn ($c) => $c->type === 'text')
            ?? $byName->get('title');
        $statusCol = $byName->get('status')
            ?? $sheet->columns->firstWhere('type', 'status');
        $ownerCol = $byName->get('owner')
            ?? $sheet->columns->firstWhere('type', 'person');
        $dueCol = $byName->get('due')
            ?? $sheet->columns->firstWhere('type', 'date');
        $priorityCol = $byName->get('priority');

        // Clear existing rows so sample data is clean/repeatable.
        SheetRow::where('sheet_id', $sheet->id)->delete();

        $samples = [
            [
                'title' => 'Discovery & scoping',
                'description' => "Define the problem, success metrics, and out-of-scope items.\nKeep the title short — put detail here.",
                'status' => 'Done',
                'priority' => 'High',
                'due' => now()->subDays(14)->toDateString(),
                'subs' => [
                    ['title' => 'Stakeholder interviews', 'status' => 'Done', 'priority' => 'Medium', 'due' => now()->subDays(18)->toDateString()],
                    ['title' => 'Success metrics defined', 'status' => 'Done', 'priority' => 'High', 'due' => now()->subDays(14)->toDateString()],
                ],
            ],
            [
                'title' => 'MVP build',
                'description' => "Ship the smallest usable flow end-to-end.\nBlockers should be marked Stuck with a comment on why.",
                'status' => 'In Progress',
                'priority' => 'Critical',
                'due' => now()->addDays(10)->toDateString(),
                'assign_owner' => true, // one sample assigned; everything else Unassigned
                'subs' => [
                    ['title' => 'Auth & roles', 'status' => 'Done', 'priority' => 'High', 'due' => now()->subDays(3)->toDateString()],
                    ['title' => 'Core workflow UI', 'status' => 'In Progress', 'priority' => 'Critical', 'due' => now()->addDays(5)->toDateString()],
                    ['title' => 'Notifications', 'status' => 'To Do', 'priority' => 'Medium', 'due' => now()->addDays(10)->toDateString()],
                ],
            ],
            [
                'title' => 'Integrations',
                'description' => 'Waiting on Azure app credentials for Graph mail. Comment with ETA when unblocked.',
                'status' => 'Stuck',
                'priority' => 'High',
                'due' => now()->addDays(3)->toDateString(),
                'subs' => [
                    ['title' => 'Microsoft Graph mail', 'status' => 'Stuck', 'priority' => 'High', 'due' => now()->addDays(2)->toDateString()],
                    ['title' => 'Webhook retry handling', 'status' => 'To Do', 'priority' => 'Medium', 'due' => now()->addDays(7)->toDateString()],
                ],
            ],
            [
                'title' => 'UAT & launch prep',
                'status' => 'To Do',
                'priority' => 'Medium',
                'due' => now()->addDays(21)->toDateString(),
                'subs' => [
                    ['title' => 'Test scripts', 'status' => 'To Do', 'priority' => 'Medium', 'due' => now()->addDays(14)->toDateString()],
                    ['title' => 'Go-live checklist', 'status' => 'To Do', 'priority' => 'Low', 'due' => now()->addDays(21)->toDateString()],
                ],
            ],
            [
                'title' => 'Post-launch monitoring',
                'status' => 'To Do',
                'priority' => 'Low',
                'due' => now()->addDays(30)->toDateString(),
                'subs' => [],
            ],
        ];

        $valuesFor = function (array $item, int $ownerIndex) use ($titleCol, $statusCol, $ownerCol, $dueCol, $priorityCol, $pickOwner) {
            $values = [];
            if ($titleCol) {
                $values[(string) $titleCol->id] = $item['title'];
            }
            if ($statusCol) {
                $values[(string) $statusCol->id] = $item['status'];
            }
            // Owner stays Unassigned unless the sample explicitly sets assign_owner.
            if ($ownerCol && ! empty($item['assign_owner'])) {
                $values[(string) $ownerCol->id] = $pickOwner($ownerIndex);
            }
            if ($dueCol) {
                $values[(string) $dueCol->id] = $item['due'];
            }
            if ($priorityCol) {
                $values[(string) $priorityCol->id] = $item['priority'];
            }

            return $values;
        };

        $pos = 0;
        foreach ($samples as $i => $item) {
            $parent = SheetRow::create([
                'sheet_id' => $sheet->id,
                'parent_id' => null,
                'position' => $pos++,
                'values' => $valuesFor($item, $i),
                'description' => $item['description'] ?? null,
            ]);

            $subPos = 0;
            foreach ($item['subs'] as $j => $sub) {
                SheetRow::create([
                    'sheet_id' => $sheet->id,
                    'parent_id' => $parent->id,
                    'position' => $subPos++,
                    'values' => $valuesFor($sub, $i + $j + 1),
                ]);
            }
        }

        $count = SheetRow::where('sheet_id', $sheet->id)->count();
        echo "Seeded {$count} rows on sheet \"{$sheet->name}\" (id {$sheet->id})\n";
    }
});

echo "Done.\n";
