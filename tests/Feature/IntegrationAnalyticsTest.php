<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Group;
use App\Models\Item;
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
}
