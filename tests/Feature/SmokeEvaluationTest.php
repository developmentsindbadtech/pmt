<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Group;
use App\Models\Item;
use App\Models\Sheet;
use App\Models\SheetColumn;
use App\Models\SheetRow;
use App\Models\SheetRowComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SmokeEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Tests should not depend on a compiled Vite manifest / running dev server.
        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function user(): User
    {
        return User::factory()->create(['is_admin' => false]);
    }

    private function board(string $name = 'Board'): Board
    {
        return Board::create(['name' => $name, 'view_type' => 'kanban', 'created_by' => $this->admin()->id]);
    }

    // ---------- Auth / access ----------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('boards.index'))->assertRedirect(route('login'));
        $this->get(route('sheets.index'))->assertRedirect(route('login'));
    }

    public function test_regular_user_only_sees_assigned_boards(): void
    {
        $user = $this->user();
        $assigned = $this->board('Assigned');
        $other = $this->board('Hidden');
        $assigned->users()->attach($user->id);

        $this->actingAs($user)->get(route('boards.index'))
            ->assertOk()
            ->assertSee('Assigned')
            ->assertDontSee('Hidden');
    }

    public function test_admin_sees_all_boards(): void
    {
        $admin = $this->admin();
        $this->board('Alpha');
        $this->board('Beta');

        $this->actingAs($admin)->get(route('boards.index'))
            ->assertOk()->assertSee('Alpha')->assertSee('Beta');
    }

    public function test_regular_user_forbidden_on_unassigned_board(): void
    {
        $user = $this->user();
        $board = $this->board('Secret');

        $this->actingAs($user)->get(route('boards.show', $board))->assertForbidden();

        $board->users()->attach($user->id);
        $this->actingAs($user)->get(route('boards.show', $board))->assertOk();
    }

    // ---------- Sheets access ----------

    public function test_regular_user_only_sees_assigned_sheets(): void
    {
        $user = $this->user();
        $admin = $this->admin();
        $assigned = Sheet::create(['name' => 'MySheet', 'created_by' => $admin->id]);
        $hidden = Sheet::create(['name' => 'NopeSheet', 'created_by' => $admin->id]);
        $assigned->users()->attach([$user->id, $admin->id]);
        $hidden->users()->attach($admin->id);

        $this->actingAs($user)->get(route('sheets.index'))
            ->assertOk()->assertSee('MySheet')->assertDontSee('NopeSheet');

        // Admin index is personal too — only sheets they own / are on (not every sheet in the system).
        $this->actingAs($admin)->get(route('sheets.index'))
            ->assertOk()->assertSee('MySheet')->assertSee('NopeSheet');

        $other = Sheet::create(['name' => 'SomeoneElseSheet', 'created_by' => $user->id]);
        $other->users()->attach($user->id);
        $this->actingAs($admin)->get(route('sheets.index'))
            ->assertOk()->assertDontSee('SomeoneElseSheet');
        // Admin can still open any sheet directly for support.
        $this->actingAs($admin)->get(route('sheets.show', $other))->assertOk();
    }

    public function test_regular_user_forbidden_on_unassigned_sheet(): void
    {
        $user = $this->user();
        $sheet = Sheet::create(['name' => 'Locked', 'created_by' => $this->admin()->id]);

        $this->actingAs($user)->get(route('sheets.show', $sheet))->assertForbidden();

        $sheet->users()->attach($user->id);
        $this->actingAs($user)->get(route('sheets.show', $sheet))->assertOk();
    }

    public function test_creating_sheet_seeds_columns_and_assigns_creator(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('sheets.store'), [
            'name' => 'Fresh Sheet',
        ])->assertRedirect();

        $sheet = Sheet::where('name', 'Fresh Sheet')->firstOrFail();
        $this->assertEquals(5, $sheet->columns()->count());
        $this->assertTrue($sheet->columns()->where('name', 'Owner')->where('type', 'person')->exists());
        $this->assertTrue($sheet->users()->where('users.id', $admin->id)->exists());
    }

    // ---------- User management (sheet assignment + sorting) ----------

    public function test_admin_can_assign_users_to_sheet_and_regular_cannot(): void
    {
        $admin = $this->admin();
        $user = $this->user();
        $sheet = Sheet::create(['name' => 'Assignable', 'created_by' => $admin->id]);
        $sheet->users()->attach($admin->id);

        $this->actingAs($admin)->put(route('user-management.update-sheet', $sheet), [
            'user_ids' => [$user->id],
        ])->assertRedirect();
        $this->assertTrue($sheet->fresh()->users()->where('users.id', $user->id)->exists());
        // Owner stays even if omitted from the request payload.
        $this->assertTrue($sheet->fresh()->users()->where('users.id', $admin->id)->exists());

        // Clearing extra people keeps the owner.
        $this->actingAs($admin)->put(route('user-management.update-sheet', $sheet), [
            'user_ids' => [],
        ])->assertRedirect();
        $this->assertFalse($sheet->fresh()->users()->where('users.id', $user->id)->exists());
        $this->assertTrue($sheet->fresh()->users()->where('users.id', $admin->id)->exists());

        // Regular user cannot access user management update
        $this->actingAs($user)->put(route('user-management.update-sheet', $sheet), [
            'user_ids' => [],
        ])->assertForbidden();
    }

    public function test_granting_board_and_sheet_access_emails_new_members_when_graph_configured(): void
    {
        config([
            'services.microsoft.client_id' => 'test-client',
            'services.microsoft.client_secret' => 'test-secret',
        ]);

        $admin = $this->admin();
        $user = $this->user();
        $board = $this->board('NotifyBoard');
        $sheet = Sheet::create(['name' => 'NotifySheet', 'created_by' => $admin->id]);
        $sheet->users()->attach($admin->id);

        $this->mock(\App\Services\MicrosoftGraphMailService::class, function ($mock) use ($user) {
            $mock->shouldReceive('sendEmail')
                ->twice()
                ->withArgs(fn ($email) => $email === $user->email)
                ->andReturn(true);
        });

        $this->actingAs($admin)->put(route('user-management.update', $board), [
            'user_ids' => [$user->id],
        ])->assertRedirect();

        $this->actingAs($admin)->put(route('user-management.update-sheet', $sheet), [
            'user_ids' => [$user->id],
        ])->assertRedirect();
    }

    public function test_regular_user_can_create_own_sheet_and_is_owner(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post(route('sheets.store'), [
            'name' => 'Personal Sheet',
        ])->assertRedirect();

        $sheet = Sheet::where('name', 'Personal Sheet')->firstOrFail();
        $this->assertEquals($user->id, $sheet->created_by);
        $this->assertTrue($sheet->users()->where('users.id', $user->id)->exists());

        $this->actingAs($user)->get(route('sheets.index'))
            ->assertOk()->assertSee('Personal Sheet');
    }

    public function test_user_management_sort_param_is_accepted(): void
    {
        $admin = $this->admin();
        $this->board('Zeta');
        $this->board('Alpha');

        $this->actingAs($admin)->get(route('user-management.index', ['board_sort' => 'desc']))->assertOk();
        $this->actingAs($admin)->get(route('user-management.index', ['sheet_sort' => 'desc']))->assertOk();
    }

    // ---------- Sheet grid component ----------

    public function test_sheet_grid_row_column_subtask_and_cell_operations(): void
    {
        $admin = $this->admin();
        $sheet = Sheet::create(['name' => 'Grid', 'created_by' => $admin->id]);
        $sheet->users()->attach($admin->id);
        $col = SheetColumn::create(['sheet_id' => $sheet->id, 'name' => 'Title', 'type' => 'text', 'position' => 0]);

        $component = Livewire::actingAs($admin)->test('sheet-grid', ['sheetId' => $sheet->id]);

        // Add a top-level row
        $component->call('addRow');
        $this->assertEquals(1, SheetRow::where('sheet_id', $sheet->id)->whereNull('parent_id')->count());
        $parent = SheetRow::where('sheet_id', $sheet->id)->first();

        // Add a subtask under it
        $component->call('addSubRow', $parent->id);
        $this->assertEquals(1, SheetRow::where('sheet_id', $sheet->id)->where('parent_id', $parent->id)->count());

        // Edit a cell
        $component->call('updateCell', $parent->id, $col->id, 'Hello');
        $this->assertEquals('Hello', data_get($parent->fresh()->values, (string) $col->id));

        // Add a new column
        $component->set('newColName', 'Owner')->set('newColType', 'text')->call('addColumn');
        $this->assertEquals(2, SheetColumn::where('sheet_id', $sheet->id)->count());

        // Sort toggling
        $component->call('sortBy', $col->id)->assertSet('sortColId', $col->id)->assertSet('sortDir', 'asc');
        $component->call('sortBy', $col->id)->assertSet('sortDir', 'desc');
        $component->call('clearSort')->assertSet('sortColId', null);

        // Deleting the parent cascades to subtasks
        $component->call('deleteRow', $parent->id);
        $this->assertEquals(0, SheetRow::where('sheet_id', $sheet->id)->count());
    }

    public function test_sheet_row_detail_sidebar_supports_description_and_comments(): void
    {
        $admin = $this->admin();
        $sheet = Sheet::create(['name' => 'Details', 'created_by' => $admin->id]);
        $sheet->users()->attach($admin->id);
        $row = SheetRow::create(['sheet_id' => $sheet->id, 'position' => 0, 'values' => []]);

        $component = Livewire::actingAs($admin)->test('sheet-grid', ['sheetId' => $sheet->id]);

        $other = SheetRow::create(['sheet_id' => $sheet->id, 'position' => 1, 'values' => [], 'description' => 'Other notes']);

        $component->call('openRow', $row->id)->assertSet('selectedRowId', $row->id);
        $component->set('descriptionDraft', 'Acceptance criteria go here')->call('persistDescription');
        $this->assertEquals('Acceptance criteria go here', $row->fresh()->description);

        // Switching items keeps the panel open and saves the previous draft.
        $component->set('descriptionDraft', 'Updated before switch');
        $component->call('openRow', $other->id)
            ->assertSet('selectedRowId', $other->id)
            ->assertSet('descriptionDraft', 'Other notes');
        $this->assertEquals('Updated before switch', $row->fresh()->description);

        $component->set('commentBody', 'Looks good — ship it.')->call('addComment');
        $this->assertEquals(1, SheetRowComment::where('sheet_row_id', $other->id)->count());

        $comment = SheetRowComment::where('sheet_row_id', $other->id)->first();
        $component->call('deleteComment', $comment->id);
        $this->assertEquals(0, SheetRowComment::where('sheet_row_id', $other->id)->count());

        $component->call('closeRow')->assertSet('selectedRowId', null);
    }

    public function test_sheet_mentionable_users_and_comment_mentions(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['is_admin' => false, 'name' => 'Alice Member']);
        $stranger = User::factory()->create(['is_admin' => false, 'name' => 'Bob Stranger']);

        $sheet = Sheet::create(['name' => 'Mentions', 'created_by' => $admin->id]);
        $sheet->users()->attach([$admin->id, $member->id]);
        $row = SheetRow::create(['sheet_id' => $sheet->id, 'position' => 0, 'values' => []]);

        $this->actingAs($admin)
            ->getJson(route('api.sheets.mentionable-users', $sheet))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Alice Member'])
            ->assertJsonMissing(['name' => 'Bob Stranger']);

        $ids = app(\App\Services\MentionService::class)
            ->extractMentionsForSheet('Hey @Alice please check', $sheet);
        $this->assertContains($member->id, $ids);
        $this->assertNotContains($stranger->id, $ids);

        Livewire::actingAs($admin)
            ->test('sheet-grid', ['sheetId' => $sheet->id])
            ->call('openRow', $row->id)
            ->set('commentBody', 'Ping @Alice on this')
            ->call('addComment');

        $this->assertDatabaseHas('sheet_row_comments', [
            'sheet_row_id' => $row->id,
            'body' => 'Ping @Alice on this',
        ]);
    }

    public function test_person_column_only_lists_admins_and_assigned_users(): void
    {
        $admin = $this->admin();
        $assigned = User::factory()->create(['is_admin' => false, 'name' => 'Assigned Person']);
        $stranger = User::factory()->create(['is_admin' => false, 'name' => 'Stranger Person']);

        $sheet = Sheet::create(['name' => 'People', 'created_by' => $admin->id]);
        $sheet->users()->attach([$admin->id, $assigned->id]);
        SheetColumn::create(['sheet_id' => $sheet->id, 'name' => 'Owner', 'type' => 'person', 'position' => 0]);
        SheetRow::create(['sheet_id' => $sheet->id, 'position' => 0, 'values' => []]);

        Livewire::actingAs($admin)->test('sheet-grid', ['sheetId' => $sheet->id])
            ->assertSee('Assigned Person')
            ->assertSee($admin->name)
            ->assertDontSee('Stranger Person');
    }

    // ---------- Table view bulk actions ----------

    public function test_table_view_bulk_delete_and_status_change(): void
    {
        $admin = $this->admin();
        $board = $this->board('BulkBoard');
        $todo = Group::create(['board_id' => $board->id, 'name' => 'To Do', 'position' => 0]);
        $done = Group::create(['board_id' => $board->id, 'name' => 'Done', 'position' => 1]);

        $items = [];
        foreach (range(1, 3) as $i) {
            $items[] = Item::create([
                'board_id' => $board->id,
                'number' => $i,
                'name' => 'Item '.$i,
                'item_type' => 'task',
                'priority' => 'medium',
                'group_id' => $todo->id,
                'position' => $i,
                'created_by' => $admin->id,
            ]);
        }

        // Bulk status change: move first two to "Done"
        Livewire::actingAs($admin)->test('table-view', ['boardId' => $board->id])
            ->set('selectedItemIds', [$items[0]->id, $items[1]->id])
            ->set('bulkAction', 'status:'.$done->id)
            ->call('applyBulkAction');
        $this->assertEquals($done->id, $items[0]->fresh()->group_id);
        $this->assertEquals($done->id, $items[1]->fresh()->group_id);
        $this->assertEquals($todo->id, $items[2]->fresh()->group_id);

        // Bulk delete the third
        Livewire::actingAs($admin)->test('table-view', ['boardId' => $board->id])
            ->set('selectedItemIds', [$items[2]->id])
            ->call('deleteSelected');
        $this->assertDatabaseMissing('items', ['id' => $items[2]->id]);
    }

    public function test_sheet_default_open_filter_and_archive_hides_rows(): void
    {
        $admin = $this->admin();
        $sheet = Sheet::create(['name' => 'ArchiveSheet', 'created_by' => $admin->id]);
        $sheet->users()->attach($admin->id);
        $title = SheetColumn::create([
            'sheet_id' => $sheet->id,
            'name' => 'Title',
            'type' => 'text',
            'position' => 0,
        ]);
        $status = SheetColumn::create([
            'sheet_id' => $sheet->id,
            'name' => 'Status',
            'type' => 'status',
            'position' => 1,
            'options' => ['To Do', 'Done'],
        ]);
        $open = SheetRow::create([
            'sheet_id' => $sheet->id,
            'position' => 0,
            'values' => [
                (string) $title->id => 'Open Alpha Task',
                (string) $status->id => 'To Do',
            ],
        ]);
        $done = SheetRow::create([
            'sheet_id' => $sheet->id,
            'position' => 1,
            'values' => [
                (string) $title->id => 'Done Beta Task',
                (string) $status->id => 'Done',
            ],
        ]);

        $component = Livewire::actingAs($admin)->test('sheet-grid', ['sheetId' => $sheet->id]);
        $component->assertSet('filter', 'open');
        $component->assertSee('Open Alpha Task')->assertDontSee('Done Beta Task');

        $component->call('setFilter', 'done')->assertSee('Done Beta Task')->assertDontSee('Open Alpha Task');
        $component->call('archiveRow', $done->id);
        $this->assertNotNull($done->fresh()->archived_at);

        $component->call('setFilter', 'archived')->assertSee('Done Beta Task');
        $component->call('unarchiveRow', $done->id);
        $this->assertNull($done->fresh()->archived_at);
        $this->assertNull($open->fresh()->archived_at);
    }

    public function test_board_hides_done_by_default_and_archives_items(): void
    {
        $admin = $this->admin();
        $board = $this->board('ArchiveBoard');
        $board->users()->attach($admin->id);
        $todo = Group::create(['board_id' => $board->id, 'name' => 'To Do', 'position' => 0]);
        $done = Group::create(['board_id' => $board->id, 'name' => 'Done', 'position' => 1]);

        $activeItem = Item::create([
            'board_id' => $board->id,
            'number' => 1,
            'name' => 'Active Work',
            'item_type' => 'task',
            'priority' => 'medium',
            'group_id' => $todo->id,
            'position' => 0,
            'created_by' => $admin->id,
        ]);
        $doneItem = Item::create([
            'board_id' => $board->id,
            'number' => 2,
            'name' => 'Finished Work',
            'item_type' => 'task',
            'priority' => 'medium',
            'group_id' => $done->id,
            'position' => 0,
            'created_by' => $admin->id,
        ]);

        Livewire::actingAs($admin)->test('board-content', ['boardId' => $board->id])
            ->assertSet('showDone', false)
            ->assertSee('Show Done');

        Livewire::actingAs($admin)->test('kanban-view', ['boardId' => $board->id])
            ->assertSet('showDone', false)
            ->assertSee('Active Work')
            ->assertDontSee('Finished Work');

        Livewire::actingAs($admin)->test('kanban-view', ['boardId' => $board->id, 'showDone' => true])
            ->assertSee('Active Work')
            ->assertSee('Finished Work');

        $this->actingAs($admin)
            ->postJson(route('items.archive', [$board, $activeItem]))
            ->assertOk();
        $this->assertNotNull($activeItem->fresh()->archived_at);

        Livewire::actingAs($admin)->test('kanban-view', ['boardId' => $board->id])
            ->assertDontSee('Active Work');

        Livewire::actingAs($admin)->test('kanban-view', [
            'boardId' => $board->id,
            'itemVisibility' => 'archived',
        ])->assertSee('Active Work');

        $this->actingAs($admin)
            ->postJson(route('items.unarchive', [$board, $activeItem]))
            ->assertOk();
        $this->assertNull($activeItem->fresh()->archived_at);

        Livewire::actingAs($admin)->test('table-view', ['boardId' => $board->id])
            ->set('selectedItemIds', [$doneItem->id])
            ->set('bulkAction', 'archive')
            ->call('applyBulkAction');
        $this->assertNotNull($doneItem->fresh()->archived_at);
    }
}
