<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Sheet;
use App\Models\User;
use App\Services\NavList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavSidebarTest extends TestCase
{
    use RefreshDatabase;

    public function test_pin_puts_a_late_board_above_the_sidebar_cap(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $boards = collect(range(1, 31))->map(fn (int $n) => Board::create([
            'name' => sprintf('Board %02d', $n),
            'view_type' => 'kanban',
            'created_by' => $admin->id,
        ]));

        $last = $boards->firstWhere('name', 'Board 31');

        $this->actingAs($admin)->post(route('nav.prefs'), [
            'type' => 'board',
            'id' => $last->id,
            'action' => 'pin',
        ])->assertRedirect();

        $names = NavList::boards($admin)['items']->pluck('name')->all();

        $this->assertSame('Board 31', $names[0]);
        $this->assertCount(30, $names);
        $this->assertNotContains('Board 30', $names);
    }

    public function test_hide_is_personal_and_search_still_finds_the_board(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $other = User::factory()->create(['is_admin' => true]);
        $board = Board::create([
            'name' => 'TI3.1 Parked',
            'view_type' => 'kanban',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post(route('nav.prefs'), [
            'type' => 'board',
            'id' => $board->id,
            'action' => 'hide',
        ])->assertRedirect();

        $this->assertNotContains('TI3.1 Parked', NavList::boards($admin)['items']->pluck('name'));
        $this->assertContains('TI3.1 Parked', NavList::boards($other)['items']->pluck('name'));

        $found = $this->actingAs($admin)->getJson(route('nav.search', ['q' => 'TI3.1']))
            ->assertOk()
            ->json('boards');

        $this->assertSame($board->id, $found[0]['id']);
        $this->assertTrue($found[0]['hidden']);
    }

    public function test_pin_brings_a_hidden_board_back(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $board = Board::create([
            'name' => 'TO4.2 Active',
            'view_type' => 'kanban',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post(route('nav.prefs'), [
            'type' => 'board',
            'id' => $board->id,
            'action' => 'hide',
        ]);
        $this->actingAs($admin)->post(route('nav.prefs'), [
            'type' => 'board',
            'id' => $board->id,
            'action' => 'pin',
        ]);

        $items = NavList::boards($admin)['items'];
        $this->assertTrue($items->firstWhere('name', 'TO4.2 Active')->nav_pinned);
    }

    public function test_sheet_pin_and_hide_follow_the_same_rules(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $sheet = Sheet::create(['name' => 'Ops Sheet', 'created_by' => $admin->id]);

        $this->actingAs($admin)->post(route('nav.prefs'), [
            'type' => 'sheet',
            'id' => $sheet->id,
            'action' => 'pin',
        ])->assertRedirect();

        $this->assertTrue(NavList::sheets($admin)['items']->first()->nav_pinned);

        $this->actingAs($admin)->post(route('nav.prefs'), [
            'type' => 'sheet',
            'id' => $sheet->id,
            'action' => 'hide',
        ]);

        $this->assertCount(0, NavList::sheets($admin)['items']);
        $this->assertTrue($this->actingAs($admin)->getJson(route('nav.search', ['q' => 'Ops']))->json('sheets.0.hidden'));
    }

    public function test_boards_page_shows_pin_and_hide_for_every_user(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $admin = User::factory()->create(['is_admin' => true]);
        $board = Board::create([
            'name' => 'Shared Board',
            'view_type' => 'kanban',
            'created_by' => $admin->id,
        ]);
        $board->users()->attach($user->id);

        $this->actingAs($user)->get(route('boards.index'))
            ->assertOk()
            ->assertSee('>Pin</button>', false)
            ->assertSee('>Hide</button>', false)
            ->assertSee('only your sidebar');

        $this->actingAs($user)->post(route('nav.prefs'), [
            'type' => 'board',
            'id' => $board->id,
            'action' => 'pin',
        ]);

        $this->actingAs($user)->get(route('boards.index'))
            ->assertOk()
            ->assertSee('>Unpin</button>', false);

        $this->actingAs($user)->post(route('nav.prefs'), [
            'type' => 'board',
            'id' => $board->id,
            'action' => 'hide',
        ]);

        $this->actingAs($user)->get(route('boards.index'))
            ->assertOk()
            ->assertSee('>Unhide</button>', false);

        $this->actingAs($admin)->get(route('boards.index'))
            ->assertOk()
            ->assertSee('>Pin</button>', false)
            ->assertDontSee('>Unpin</button>', false);
    }

    public function test_sheets_page_shows_pin_and_hide_for_every_user(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $admin = User::factory()->create(['is_admin' => true]);
        $sheet = Sheet::create([
            'name' => 'Shared Sheet',
            'created_by' => $admin->id,
        ]);
        $sheet->users()->attach($user->id);

        $this->actingAs($user)->get(route('sheets.index'))
            ->assertOk()
            ->assertSee('>Pin</button>', false)
            ->assertSee('>Hide</button>', false)
            ->assertSee('only your sidebar');

        $this->actingAs($user)->post(route('nav.prefs'), [
            'type' => 'sheet',
            'id' => $sheet->id,
            'action' => 'pin',
        ]);

        $this->actingAs($user)->get(route('sheets.index'))
            ->assertOk()
            ->assertSee('>Unpin</button>', false);
    }

    public function test_user_cannot_pin_a_board_they_cannot_see(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $admin = User::factory()->create(['is_admin' => true]);
        $board = Board::create([
            'name' => 'Secret',
            'view_type' => 'kanban',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($user)->post(route('nav.prefs'), [
            'type' => 'board',
            'id' => $board->id,
            'action' => 'pin',
        ])->assertForbidden();
    }
}
