<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Group;
use App\Models\Item;
use App\Models\ItemComment;
use App\Models\Sheet;
use App\Models\SheetRow;
use App\Models\SheetRowComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClickableTextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_sheet_details_and_comments_render_links(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $sheet = Sheet::create(['name' => 'Links', 'created_by' => $admin->id]);
        $sheet->users()->attach($admin->id);
        $row = SheetRow::create([
            'sheet_id' => $sheet->id,
            'position' => 0,
            'values' => [],
            'description' => 'Spec at https://example.com/spec. <script>alert(1)</script>',
        ]);
        SheetRowComment::create([
            'sheet_row_id' => $row->id,
            'user_id' => $admin->id,
            'body' => 'See [notes](https://example.com/notes) @sam',
        ]);

        Livewire::actingAs($admin)->test('sheet-grid', ['sheetId' => $sheet->id])
            ->call('openRow', $row->id)
            ->assertSee('href="https://example.com/spec"', false)
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>', false)
            ->assertSee('href="https://example.com/notes"', false)
            ->assertSee('>notes</a>', false)
            ->assertSee('@sam', false);
    }

    public function test_board_details_and_comments_render_links(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $board = Board::create(['name' => 'Board', 'view_type' => 'kanban', 'created_by' => $admin->id]);
        $board->users()->attach($admin->id);
        $group = Group::create(['board_id' => $board->id, 'name' => 'To Do', 'position' => 0]);

        $task = Item::create([
            'board_id' => $board->id,
            'number' => 1,
            'name' => 'Write spec',
            'item_type' => 'task',
            'priority' => 'medium',
            'group_id' => $group->id,
            'position' => 0,
            'created_by' => $admin->id,
            'description' => 'Draft at https://example.com/draft.',
        ]);
        ItemComment::create([
            'item_id' => $task->id,
            'user_id' => $admin->id,
            'body' => 'Review [this](https://example.com/review)',
        ]);

        Livewire::actingAs($admin)->test('board-content', [
            'boardId' => $board->id,
            'selectedItemId' => $task->id,
        ])
            ->assertSee('href="https://example.com/draft"', false)
            ->assertSee('href="https://example.com/review"', false)
            ->assertSee('>this</a>', false);

        $bug = Item::create([
            'board_id' => $board->id,
            'number' => 2,
            'name' => 'Broken link',
            'item_type' => 'bug',
            'priority' => 'medium',
            'group_id' => $group->id,
            'position' => 1,
            'created_by' => $admin->id,
            'repro_steps' => 'Open https://example.com/repro',
        ]);

        Livewire::actingAs($admin)->test('board-content', [
            'boardId' => $board->id,
            'selectedItemId' => $bug->id,
        ])->assertSee('href="https://example.com/repro"', false);
    }
}
