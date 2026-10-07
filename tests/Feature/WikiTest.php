<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_any_signed_in_person_can_read_and_add_a_page(): void
    {
        $ada = User::factory()->create(['is_admin' => false, 'name' => 'Ada Admin']);
        $sam = User::factory()->create(['is_admin' => false, 'name' => 'Sam Owner']);

        $this->actingAs($ada)->post(route('wiki.store'), [
            'title' => 'How we ship',
            'body' => "# Ship\n\n1. Open a ticket\n2. Move it to **Closed**",
        ])->assertRedirect();

        $page = WikiPage::query()->firstOrFail();
        $this->assertSame($ada->id, $page->created_by);
        $this->assertSame($ada->id, $page->updated_by);
        $this->assertSame('how-we-ship', $page->slug);

        $this->actingAs($sam)->get(route('wiki.index'))
            ->assertOk()
            ->assertSee('How we ship')
            ->assertSee('Ada Admin')
            ->assertSee('Wiki');

        $this->actingAs($sam)->get(route('wiki.show', $page))
            ->assertOk()
            ->assertSee('Created by Ada Admin')
            ->assertSee('Last edited')
            ->assertSee('Ship', false)
            ->assertSee('Closed', false);
    }

    public function test_an_edit_keeps_the_author_and_records_the_last_editor(): void
    {
        $ada = User::factory()->create(['name' => 'Ada']);
        $sam = User::factory()->create(['name' => 'Sam']);
        $page = WikiPage::create([
            'title' => 'Office hours',
            'slug' => 'office-hours',
            'body' => 'Nine to five',
            'created_by' => $ada->id,
            'updated_by' => $ada->id,
        ]);

        $this->actingAs($sam)->put(route('wiki.update', $page), [
            'title' => 'Office hours',
            'body' => 'Eight to four',
        ])->assertRedirect(route('wiki.show', $page));

        $page->refresh();
        $this->assertSame($ada->id, $page->created_by);
        $this->assertSame($sam->id, $page->updated_by);
        $this->assertSame('Eight to four', $page->body);

        $this->actingAs($ada)->get(route('wiki.show', $page))
            ->assertOk()
            ->assertSee('Created by Ada')
            ->assertSee('by Sam');
    }

    public function test_only_the_author_or_an_admin_can_delete_a_page(): void
    {
        $author = User::factory()->create(['is_admin' => false]);
        $other = User::factory()->create(['is_admin' => false]);
        $admin = User::factory()->create(['is_admin' => true]);
        $page = WikiPage::create([
            'title' => 'Scratch',
            'slug' => 'scratch',
            'body' => 'notes',
            'created_by' => $author->id,
            'updated_by' => $author->id,
        ]);

        $this->actingAs($other)->delete(route('wiki.destroy', $page))->assertForbidden();
        $this->assertTrue(WikiPage::query()->whereKey($page->id)->exists());

        $this->actingAs($admin)->delete(route('wiki.destroy', $page))->assertRedirect(route('wiki.index'));
        $this->assertFalse(WikiPage::query()->whereKey($page->id)->exists());
    }

    public function test_html_in_a_page_is_stripped(): void
    {
        $user = User::factory()->create();
        $page = WikiPage::create([
            'title' => 'Safe notes',
            'slug' => 'safe-notes',
            'body' => "Hello <script>alert(1)</script>\n\n**ok**",
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $html = $page->html();
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('<strong>ok</strong>', $html);
    }

    public function test_sidebar_search_finds_wiki_pages(): void
    {
        $user = User::factory()->create();
        WikiPage::create([
            'title' => 'VPN setup',
            'slug' => 'vpn-setup',
            'body' => '',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->actingAs($user)->getJson(route('nav.search', ['q' => 'VPN']))
            ->assertOk()
            ->assertJsonPath('wiki.0.name', 'VPN setup')
            ->assertJsonPath('wiki.0.slug', 'vpn-setup');
    }
}
