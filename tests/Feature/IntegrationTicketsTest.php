<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\Column;
use App\Models\Group;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntegrationTicketsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

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

    private function boardWithGroup(string $name): Board
    {
        $owner = User::factory()->create(['is_admin' => true]);
        $board = Board::create([
            'name' => $name,
            'view_type' => 'kanban',
            'created_by' => $owner->id,
        ]);
        Group::create(['board_id' => $board->id, 'name' => 'To Do', 'position' => 0]);
        Column::create([
            'board_id' => $board->id,
            'name' => 'Status',
            'type' => 'status',
            'position' => 0,
            'settings' => ['options' => ['To Do', 'Done']],
        ]);

        return $board;
    }

    public function test_requires_bearer_token_not_session_cookie(): void
    {
        $user = $this->integrationUser();
        $this->boardWithGroup('OO4.2_P2 DPM Operations');

        $this->actingAs($user)
            ->postJson('/api/integrations/tickets', [
                'boardName' => 'OO4.2_P2 DPM Operations',
                'items' => [[
                    'externalKey' => 'k1',
                    'name' => 'Task',
                    'itemType' => 'task',
                    'priority' => 'medium',
                ]],
            ])
            ->assertUnauthorized()
            ->assertJsonFragment(['message' => 'Bearer token required. Session cookies are not accepted for this endpoint.']);
    }

    public function test_creates_ticket_with_assignee_and_description(): void
    {
        $integration = $this->integrationUser();
        $assignee = User::factory()->create(['email' => 'person@sindbad.tech']);
        $board = $this->boardWithGroup('OO4.2_P2 DPM Operations');

        $response = $this->postJson('/api/integrations/tickets', [
            'boardName' => 'oo4.2_p2 dpm operations',
            'meetingDate' => 'Thu, 15 Oct 2026 KSA',
            'items' => [[
                'externalKey' => 'stable-id-from-analyzer',
                'name' => 'ticket title',
                'itemType' => 'task',
                'priority' => 'high',
                'description' => 'full description',
                'assigneeEmail' => 'Person@Sindbad.tech',
                'dueAt' => '2026-10-18',
                'devTag' => 'DPM',
                'relatedTo' => 'OO4-12',
            ]],
        ], $this->tokenHeaders($integration));

        $response->assertOk()
            ->assertJsonPath('board.id', $board->id)
            ->assertJsonPath('board.name', $board->name)
            ->assertJsonPath('items.0.externalKey', 'stable-id-from-analyzer')
            ->assertJsonPath('items.0.number', 1)
            ->assertJsonPath('items.0.assigneeMatched', true)
            ->assertJsonPath('items.0.created', true);

        $item = Item::query()->where('external_key', 'stable-id-from-analyzer')->first();
        $this->assertNotNull($item);
        $this->assertSame($integration->id, $item->created_by);
        $this->assertSame($assignee->id, $item->assignee_id);
        $this->assertSame('DPM', $item->dev_tag);
        $this->assertSame('high', $item->priority);
        $this->assertStringContainsString('full description', (string) $item->description);
        $this->assertStringContainsString('Meeting: Thu, 15 Oct 2026 KSA', (string) $item->description);
        $this->assertStringContainsString('Related to: None', (string) $item->description);
        $this->assertStringContainsString('Mentioned with: OO4-12', (string) $item->description);
        $this->assertNull($item->parent_id);
        $response->assertJsonPath('items.0.relatedLabel', 'None');
        $this->assertSame(
            url('/boards/'.$board->id.'/ticket/'.$item->number),
            $response->json('items.0.url')
        );
    }

    public function test_exact_ticket_name_becomes_parent_and_loose_text_stays_none(): void
    {
        $integration = $this->integrationUser();
        $board = $this->boardWithGroup('Ops Board');
        Column::create([
            'board_id' => $board->id,
            'name' => 'Related To',
            'type' => 'text',
            'position' => 1,
        ]);

        $this->postJson('/api/integrations/tickets', [
            'boardName' => 'Ops Board',
            'items' => [
                [
                    'externalKey' => 'parent-key',
                    'name' => 'Expand DPM Connection Table',
                    'itemType' => 'task',
                    'priority' => 'medium',
                    'relatedTo' => 'None',
                ],
                [
                    'externalKey' => 'child-key',
                    'name' => 'Follow up the table work',
                    'itemType' => 'task',
                    'priority' => 'low',
                    'relatedTo' => 'Expand DPM Connection Table',
                ],
                [
                    'externalKey' => 'loose-key',
                    'name' => 'Something else',
                    'itemType' => 'task',
                    'priority' => 'low',
                    'relatedTo' => 'a vague topic',
                ],
            ],
        ], $this->tokenHeaders($integration))->assertOk()
            ->assertJsonPath('items.0.relatedLabel', 'None')
            ->assertJsonPath('items.1.relatedLabel', 'PMT #1 Expand DPM Connection Table')
            ->assertJsonPath('items.2.relatedLabel', 'None');

        $parent = Item::query()->where('external_key', 'parent-key')->first();
        $child = Item::query()->where('external_key', 'child-key')->first();
        $loose = Item::query()->where('external_key', 'loose-key')->first();
        $this->assertNotNull($parent);
        $this->assertNotNull($child);
        $this->assertNotNull($loose);
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertNull($loose->parent_id);
        $this->assertStringNotContainsString('a vague topic', (string) $loose->description);
    }

    public function test_idempotent_on_external_key(): void
    {
        $integration = $this->integrationUser();
        $this->boardWithGroup('Ops Board');

        $payload = [
            'boardName' => 'Ops Board',
            'items' => [[
                'externalKey' => 'same-key',
                'name' => 'First',
                'itemType' => 'task',
                'priority' => 'low',
            ]],
        ];

        $first = $this->postJson('/api/integrations/tickets', $payload, $this->tokenHeaders($integration))->assertOk();
        $second = $this->postJson('/api/integrations/tickets', $payload, $this->tokenHeaders($integration))->assertOk();

        $this->assertSame(1, Item::query()->where('external_key', 'same-key')->count());
        $this->assertSame($first->json('items.0.id'), $second->json('items.0.id'));
        $this->assertFalse($second->json('items.0.created'));
    }

    public function test_existing_unassigned_ticket_is_assigned_when_email_is_sent_later(): void
    {
        $integration = $this->integrationUser();
        $this->boardWithGroup('Ops Board');
        $jeremy = User::factory()->create([
            'name' => 'Jeremy Daniel E. Estrada',
            'email' => 'jeremy.estrada@sindbad.tech',
        ]);

        $this->postJson('/api/integrations/tickets', [
            'boardName' => 'Ops Board',
            'items' => [[
                'externalKey' => 'jeremy-key',
                'name' => 'Follow up with Jeremy',
                'itemType' => 'task',
                'priority' => 'medium',
            ]],
        ], $this->tokenHeaders($integration))->assertOk();

        $item = Item::query()->where('external_key', 'jeremy-key')->first();
        $this->assertNotNull($item);
        $this->assertNull($item->assignee_id);

        $this->postJson('/api/integrations/tickets', [
            'boardName' => 'Ops Board',
            'items' => [[
                'externalKey' => 'jeremy-key',
                'name' => 'Follow up with Jeremy',
                'itemType' => 'task',
                'priority' => 'medium',
                'assigneeEmail' => 'jeremy.estrada@sindbad.tech',
            ]],
        ], $this->tokenHeaders($integration))
            ->assertOk()
            ->assertJsonPath('items.0.created', false)
            ->assertJsonPath('items.0.assigneeMatched', true);

        $item->refresh();
        $this->assertSame($jeremy->id, $item->assignee_id);
        $this->assertSame(1, Item::query()->where('external_key', 'jeremy-key')->count());
    }

    public function test_unknown_assignee_creates_unassigned_ticket(): void
    {
        $integration = $this->integrationUser();
        $this->boardWithGroup('Ops Board');

        $this->postJson('/api/integrations/tickets', [
            'boardName' => 'Ops Board',
            'items' => [[
                'externalKey' => 'no-user-key',
                'name' => 'Unassigned task',
                'itemType' => 'bug',
                'priority' => 'medium',
                'assigneeEmail' => 'missing@sindbad.tech',
            ]],
        ], $this->tokenHeaders($integration))
            ->assertOk()
            ->assertJsonPath('items.0.assigneeMatched', false)
            ->assertJsonFragment(['warning' => 'No user found for missing@sindbad.tech; ticket created unassigned.']);

        $item = Item::query()->where('external_key', 'no-user-key')->first();
        $this->assertNotNull($item);
        $this->assertNull($item->assignee_id);
        $this->assertSame('bug', $item->item_type);
    }

    public function test_board_not_found_returns_404(): void
    {
        $integration = $this->integrationUser();

        $this->postJson('/api/integrations/tickets', [
            'boardName' => 'Does Not Exist',
            'items' => [[
                'externalKey' => 'k',
                'name' => 'T',
                'itemType' => 'task',
                'priority' => 'low',
            ]],
        ], $this->tokenHeaders($integration))
            ->assertNotFound()
            ->assertJsonPath('boardName', 'Does Not Exist');
    }

    public function test_ambiguous_board_name_returns_409(): void
    {
        $integration = $this->integrationUser();
        $this->boardWithGroup('Duplicate Name');
        $this->boardWithGroup('duplicate name');

        // Force exact same casing duplicates via direct insert if DB collation folds case.
        Board::query()->where('name', 'duplicate name')->update(['name' => 'Duplicate Name']);

        $matches = Board::query()->whereRaw('LOWER(name) = ?', ['duplicate name'])->count();
        $this->assertGreaterThanOrEqual(2, $matches);

        $this->postJson('/api/integrations/tickets', [
            'boardName' => 'Duplicate Name',
            'items' => [[
                'externalKey' => 'k',
                'name' => 'T',
                'itemType' => 'task',
                'priority' => 'low',
            ]],
        ], $this->tokenHeaders($integration))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Multiple boards match that name.')
            ->assertJsonStructure(['boards' => [['id', 'name']]]);
    }

    public function test_artisan_command_prints_token(): void
    {
        $this->artisan('pmt:integration-token')
            ->expectsOutputToContain('Bearer token')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'integration@pmt.sindbad.tech']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_ensure_mode_creates_token_once_without_printing(): void
    {
        $this->artisan('pmt:integration-token', ['--ensure' => true])
            ->expectsOutputToContain('Wrote new integration token')
            ->doesntExpectOutputToContain('Bearer token (store securely')
            ->assertSuccessful();

        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('local')->exists('pmt-integration-token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->artisan('pmt:integration-token', ['--ensure' => true])
            ->expectsOutputToContain('Integration token already exists; skipping')
            ->assertSuccessful();

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_sanctum_acting_as_without_bearer_still_blocked(): void
    {
        $user = $this->integrationUser();
        Sanctum::actingAs($user, ['integration:tickets']);

        $this->postJson('/api/integrations/tickets', [
            'boardName' => 'X',
            'items' => [[
                'externalKey' => 'k',
                'name' => 'T',
                'itemType' => 'task',
                'priority' => 'low',
            ]],
        ])->assertUnauthorized();
    }
}
