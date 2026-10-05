<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Group;
use App\Models\Item;
use App\Models\ItemActivity;
use App\Models\Sheet;
use App\Models\SheetColumn;
use App\Models\SheetRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_board_analytics_shows_open_closed_and_who_holds_work(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'name' => 'Ada Admin']);
        $owner = User::factory()->create(['is_admin' => false, 'name' => 'Sam Owner']);
        $outsider = User::factory()->create(['is_admin' => false]);

        $board = Board::create([
            'name' => 'KYC Onboarding',
            'view_type' => 'kanban',
            'created_by' => $admin->id,
        ]);
        $board->users()->attach($owner->id);

        $openGroup = Group::create(['board_id' => $board->id, 'name' => 'In Progress', 'position' => 0]);
        $closedGroup = Group::create(['board_id' => $board->id, 'name' => 'Closed', 'position' => 1]);

        $openItem = Item::create([
            'board_id' => $board->id,
            'number' => 4,
            'name' => 'Retrieve JWK',
            'item_type' => 'bug',
            'priority' => 'high',
            'group_id' => $openGroup->id,
            'assignee_id' => $owner->id,
            'due_at' => now()->subDays(2)->toDateString(),
            'created_by' => $admin->id,
        ]);
        $openItem->forceFill([
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(1),
        ])->save();

        $closedItem = Item::create([
            'board_id' => $board->id,
            'number' => 1,
            'name' => 'Project Setup',
            'item_type' => 'task',
            'group_id' => $closedGroup->id,
            'created_by' => $admin->id,
        ]);
        $closedItem->forceFill([
            'created_at' => now()->subDays(6),
            'updated_at' => now()->subDay(),
        ])->save();

        $activity = ItemActivity::create([
            'item_id' => $closedItem->id,
            'user_id' => $admin->id,
            'type' => 'status_changed',
            'field' => 'group_id',
            'old_value' => 'In Progress',
            'new_value' => 'Closed',
        ]);
        $activity->forceFill([
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ])->save();

        $notDone = Group::create(['board_id' => $board->id, 'name' => 'Not done', 'position' => 2]);
        $doneNamed = Group::create(['board_id' => $board->id, 'name' => 'Done', 'position' => 3]);
        $this->assertFalse($notDone->isDone());
        $this->assertFalse($doneNamed->isDone());
        $this->assertTrue($closedGroup->fresh()->isDone());

        $this->actingAs($owner)->get(route('boards.index'))
            ->assertOk()
            ->assertSee(route('boards.analytics', $board), false);

        $this->actingAs($outsider)->get(route('boards.analytics', $board))->assertForbidden();

        $this->actingAs($owner)->get(route('boards.analytics', $board))
            ->assertOk()
            ->assertSee('KYC Onboarding')
            ->assertSee('Retrieve JWK')
            ->assertSee('Sam Owner')
            ->assertSee('Who has open work')
            ->assertSee('Needs attention')
            ->assertSee('3 days')
            ->assertSee('Lead time')
            ->assertSee('Cycle time')
            ->assertSee('85%')
            ->assertSee('Time in the current column')
            ->assertSee('A ticket in Closed is done')
            ->assertSee('name="from"', false)
            ->assertSee('Status');

        $this->actingAs($owner)->get(route('boards.analytics', $board).'?from=2020-01-01&to=2020-01-02')
            ->assertOk()
            ->assertSee('Retrieve JWK')
            ->assertSee('Appears after an item reaches a finished column');

        $this->assertSame($openItem->id, Item::query()->where('name', 'Retrieve JWK')->value('id'));
    }

    public function test_sheet_analytics_uses_status_owner_and_due(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $user = User::factory()->create(['is_admin' => false, 'name' => 'Nora']);
        $sheet = Sheet::create(['name' => 'Launch checklist', 'created_by' => $user->id]);
        $title = SheetColumn::create(['sheet_id' => $sheet->id, 'name' => 'Title', 'type' => 'text', 'position' => 0]);
        $status = SheetColumn::create([
            'sheet_id' => $sheet->id,
            'name' => 'Status',
            'type' => 'status',
            'options' => ['To Do', 'Stuck', 'Done'],
            'position' => 1,
        ]);
        $owner = SheetColumn::create(['sheet_id' => $sheet->id, 'name' => 'Owner', 'type' => 'person', 'position' => 2]);
        $due = SheetColumn::create(['sheet_id' => $sheet->id, 'name' => 'Due', 'type' => 'date', 'position' => 3]);

        $stuck = SheetRow::create([
            'sheet_id' => $sheet->id,
            'position' => 0,
            'values' => [
                (string) $title->id => 'UAT prep',
                (string) $status->id => 'Stuck',
                (string) $owner->id => (string) $user->id,
                (string) $due->id => '2026-10-01',
            ],
        ]);
        $stuck->forceFill(['created_at' => '2026-09-20 09:00:00'])->save();
        SheetRow::create([
            'sheet_id' => $sheet->id,
            'position' => 1,
            'values' => [
                (string) $title->id => 'Go live',
                (string) $status->id => 'Done',
            ],
            'created_at' => '2026-09-01 09:00:00',
        ]);

        $this->actingAs($user)->get(route('sheets.index'))
            ->assertOk()
            ->assertSee(route('sheets.analytics', $sheet), false);

        $this->actingAs($user)->get(route('sheets.analytics', $sheet))
            ->assertOk()
            ->assertSee('Launch checklist')
            ->assertSee('UAT prep')
            ->assertSee('Nora')
            ->assertSee('Stuck')
            ->assertSee('Rows opened')
            ->assertSee('A row marked Done is done')
            ->assertSee('name="from"', false);

        Carbon::setTestNow();
    }
}
