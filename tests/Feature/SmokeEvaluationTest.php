<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Group;
use App\Models\Item;
use App\Models\Sheet;
use App\Models\SheetColumn;
use App\Models\SheetRow;
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
        $assigned->users()->attach($user->id);

        $this->actingAs($user)->get(route('sheets.index'))
            ->assertOk()->assertSee('MySheet')->assertDontSee('NopeSheet');

        // Admin sees both
        $this->actingAs($admin)->get(route('sheets.index'))
            ->assertOk()->assertSee('MySheet')->assertSee('NopeSheet');
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
        $this->assertEquals(2, $sheet->columns()->count());
        $this->assertTrue($sheet->users()->where('users.id', $admin->id)->exists());
    }

    // ---------- User management (sheet assignment + sorting) ----------

    public function test_admin_can_assign_users_to_sheet_and_regular_cannot(): void
    {
        $admin = $this->admin();
        $user = $this->user();
        $sheet = Sheet::create(['name' => 'Assignable', 'created_by' => $admin->id]);

        $this->actingAs($admin)->put(route('user-management.update-sheet', $sheet), [
            'user_ids' => [$user->id],
        ])->assertRedirect();
        $this->assertTrue($sheet->fresh()->users()->where('users.id', $user->id)->exists());

        // Regular user cannot access user management update
        $this->actingAs($user)->put(route('user-management.update-sheet', $sheet), [
            'user_ids' => [],
        ])->assertForbidden();
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
}
