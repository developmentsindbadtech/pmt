<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Group;
use App\Models\Item;
use App\Models\ItemActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function integrationUser(): User
    {
        return User::factory()->create([
            'name' => 'Transcript Integration',
            'email' => 'integration@pmt.sindbad.tech',
            'is_admin' => false,
        ]);
    }

    private function tokenHeaders(User $user): array
    {
        $token = $user->createToken('transcript-analyzer', ['integration:tickets'])->plainTextToken;

        return [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];
    }

    public function test_board_list_follows_the_signed_in_account(): void
    {
        $integration = $this->integrationUser();
        $member = User::factory()->create(['email' => 'member@sindbad.tech', 'is_admin' => false]);
        $admin = User::factory()->create(['email' => 'admin@sindbad.tech', 'is_admin' => true]);

        $mine = Board::create(['name' => 'Mine', 'view_type' => 'kanban', 'created_by' => $admin->id]);
        $other = Board::create(['name' => 'Other', 'view_type' => 'kanban', 'created_by' => $admin->id]);
        $mine->users()->attach($member->id);

        $this->getJson('/api/integrations/boards?email=member@sindbad.tech', $this->tokenHeaders($integration))
            ->assertOk()
            ->assertJsonCount(1, 'boards')
            ->assertJsonPath('boards.0.name', 'Mine');

        $this->getJson('/api/integrations/boards?email=admin@sindbad.tech', $this->tokenHeaders($integration))
            ->assertOk()
            ->assertJsonCount(2, 'boards');

        $this->assertNotNull($other->id);
    }

    public function test_analytics_names_aging_tickets_the_page_does_not_list(): void
    {
        $integration = $this->integrationUser();
        $member = User::factory()->create(['email' => 'member@sindbad.tech', 'is_admin' => false, 'name' => 'Mina Member']);
        $board = Board::create(['name' => 'Ops Board', 'view_type' => 'kanban', 'created_by' => $member->id]);
        $board->users()->attach($member->id);
        $progress = Group::create(['board_id' => $board->id, 'name' => 'In Progress', 'position' => 0]);

        $recent = Item::create([
            'board_id' => $board->id,
            'number' => 1,
            'name' => 'Fresh work',
            'item_type' => 'task',
            'priority' => 'low',
            'group_id' => $progress->id,
            'created_by' => $member->id,
        ]);
        $recent->forceFill(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)])->save();

        $aging = Item::create([
            'board_id' => $board->id,
            'number' => 9,
            'name' => 'Quiet aging ticket',
            'item_type' => 'task',
            'priority' => 'low',
            'group_id' => $progress->id,
            'assignee_id' => $member->id,
            'created_by' => $member->id,
        ]);
        $aging->forceFill(['created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)])->save();

        $this->getJson(
            '/api/integrations/boards/'.$board->id.'/analytics?email=member@sindbad.tech',
            $this->tokenHeaders($integration),
        )
            ->assertOk()
            ->assertJsonPath('board.name', 'Ops Board')
            ->assertJsonPath('hiddenAging.0.number', 9)
            ->assertJsonPath('range', 'week')
            ->assertJsonPath('hiddenAging.0.owner', 'Mina Member')
            ->assertJsonPath('hiddenAging.0.ownerEmail', 'member@sindbad.tech')
            ->assertJsonPath('hiddenAging.0.column', 'In Progress')
            ->assertJsonFragment(['name' => 'In Progress', 'color' => '#0ea5e9'])
            ->assertJsonMissing(['name' => 'Fresh work']);

        $this->getJson(
            '/api/integrations/boards/'.$board->id.'/analytics?email=member@sindbad.tech&range=month',
            $this->tokenHeaders($integration),
        )->assertOk()->assertJsonPath('range', 'month');
    }

    public function test_outsider_cannot_read_another_boards_analytics(): void
    {
        $integration = $this->integrationUser();
        $owner = User::factory()->create(['email' => 'owner@sindbad.tech', 'is_admin' => false]);
        $outsider = User::factory()->create(['email' => 'outsider@sindbad.tech', 'is_admin' => false]);
        $board = Board::create(['name' => 'Private', 'view_type' => 'kanban', 'created_by' => $owner->id]);
        $board->users()->attach($owner->id);

        $this->getJson(
            '/api/integrations/boards/'.$board->id.'/analytics?email=outsider@sindbad.tech',
            $this->tokenHeaders($integration),
        )->assertForbidden();

        $this->assertNotNull($outsider->id);
    }

    public function test_assignee_aging_is_the_oldest_open_tickets_on_visible_boards(): void
    {
        $integration = $this->integrationUser();
        $viewer = User::factory()->create(['email' => 'member@sindbad.tech', 'is_admin' => false, 'name' => 'Mina Member']);
        $person = User::factory()->create(['email' => 'jeremy@sindbad.tech', 'is_admin' => false, 'name' => 'Jeremy Daniel Estrada']);
        $admin = User::factory()->create(['email' => 'admin@sindbad.tech', 'is_admin' => true]);

        $alpha = Board::create(['name' => 'TI3.1_P4 NASDAQ DPM', 'view_type' => 'kanban', 'created_by' => $admin->id]);
        $beta = Board::create(['name' => 'TI3.1_P7 AI Monitoring', 'view_type' => 'kanban', 'created_by' => $admin->id]);
        $hidden = Board::create(['name' => 'Private Board', 'view_type' => 'kanban', 'created_by' => $admin->id]);
        $alpha->users()->attach($viewer->id);
        $beta->users()->attach($viewer->id);

        $progress = Group::create(['board_id' => $alpha->id, 'name' => 'In Progress', 'position' => 0]);
        $closed = Group::create(['board_id' => $alpha->id, 'name' => 'Closed', 'position' => 1]);
        $betaProgress = Group::create(['board_id' => $beta->id, 'name' => 'Ready for QA', 'position' => 0]);
        $hiddenProgress = Group::create(['board_id' => $hidden->id, 'name' => 'In Progress', 'position' => 0]);

        foreach ([30, 25, 20, 15, 10, 2] as $index => $days) {
            $item = Item::create([
                'board_id' => $alpha->id,
                'number' => $index + 1,
                'name' => 'Open '.$days,
                'item_type' => 'task',
                'priority' => 'low',
                'group_id' => $progress->id,
                'assignee_id' => $person->id,
                'created_by' => $viewer->id,
            ]);
            $item->forceFill(['created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days)])->save();
        }

        $finished = Item::create([
            'board_id' => $alpha->id,
            'number' => 99,
            'name' => 'Already closed',
            'item_type' => 'task',
            'priority' => 'low',
            'group_id' => $closed->id,
            'assignee_id' => $person->id,
            'created_by' => $viewer->id,
        ]);
        $finished->forceFill(['created_at' => now()->subDays(80)])->save();

        $moved = Item::create([
            'board_id' => $beta->id,
            'number' => 4,
            'name' => 'Moved recently',
            'item_type' => 'task',
            'priority' => 'low',
            'group_id' => $betaProgress->id,
            'assignee_id' => $person->id,
            'created_by' => $viewer->id,
        ]);
        $moved->forceFill(['created_at' => now()->subDays(40), 'updated_at' => now()->subDays(40)])->save();
        $activity = ItemActivity::create([
            'item_id' => $moved->id,
            'user_id' => $viewer->id,
            'type' => 'status_changed',
            'field' => 'group',
            'old_value' => 'New',
            'new_value' => 'Ready for QA',
        ]);
        $activity->forceFill(['created_at' => now()->subDays(3)])->save();

        $secret = Item::create([
            'board_id' => $hidden->id,
            'number' => 1,
            'name' => 'Hidden ticket',
            'item_type' => 'task',
            'priority' => 'low',
            'group_id' => $hiddenProgress->id,
            'assignee_id' => $person->id,
            'created_by' => $admin->id,
        ]);
        $secret->forceFill(['created_at' => now()->subDays(50)])->save();

        $member = $this->getJson(
            '/api/integrations/aging?email=member@sindbad.tech&assignee=jeremy@sindbad.tech',
            $this->tokenHeaders($integration),
        )->assertOk();

        $member
            ->assertJsonPath('person.name', 'Jeremy Daniel Estrada')
            ->assertJsonPath('projects.0.name', 'TI3.1_P4 NASDAQ DPM')
            ->assertJsonPath('projects.0.more', 1)
            ->assertJsonPath('projects.0.tickets.0.number', 1)
            ->assertJsonPath('projects.0.tickets.0.days', 30)
            ->assertJsonPath('projects.0.tickets.4.days', 10)
            ->assertJsonPath('projects.1.name', 'TI3.1_P7 AI Monitoring')
            ->assertJsonPath('projects.1.tickets.0.days', 3)
            ->assertJsonPath('projects.1.tickets.0.column', 'Ready for QA')
            ->assertJsonMissing(['name' => 'Already closed'])
            ->assertJsonMissing(['name' => 'Hidden ticket'])
            ->assertJsonMissing(['name' => 'Open 2']);

        $this->getJson(
            '/api/integrations/aging?email=admin@sindbad.tech&assignee=jeremy@sindbad.tech',
            $this->tokenHeaders($integration),
        )
            ->assertOk()
            ->assertJsonPath('projects.0.name', 'Private Board')
            ->assertJsonPath('projects.0.tickets.0.name', 'Hidden ticket');
    }
}
