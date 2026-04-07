<?php

namespace Tests\Feature\Website;

use App\Domain\Shared\Models\Team;
use App\Domain\Website\Actions\CreateWebsiteAction;
use App\Domain\Website\Actions\CreateWebsitePageAction;
use App\Domain\Website\Actions\EnhanceWebsiteNavigationAction;
use App\Domain\Website\Actions\UpdateWebsiteAction;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSiteControllerTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();

        $this->website = app(CreateWebsiteAction::class)->execute($this->team, [
            'name' => 'Test Site',
            'slug' => 'test-site',
        ]);
    }

    private function publishWebsite(): void
    {
        app(UpdateWebsiteAction::class)->execute($this->website, ['status' => WebsiteStatus::Published]);
        $this->website->refresh();
    }

    public function test_show_returns_404_for_unpublished_website(): void
    {
        $response = $this->getJson('/api/public/sites/test-site');

        $response->assertStatus(404);
    }

    public function test_show_returns_website_info_when_published(): void
    {
        $this->publishWebsite();

        $response = $this->getJson('/api/public/sites/test-site');

        $response->assertStatus(200)->assertJsonFragment(['slug' => 'test-site']);
    }

    public function test_submit_form_returns_404_for_fabricated_form_id(): void
    {
        $this->publishWebsite();

        // A UUID that was never stored via EnhanceWebsiteNavigationAction
        $fakeFormId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        $response = $this->postJson("/api/public/sites/test-site/forms/{$fakeFormId}", [
            'fields' => ['name' => 'Attacker'],
        ]);

        $response->assertStatus(404);
    }

    public function test_submit_form_returns_404_for_unpublished_website(): void
    {
        $fakeFormId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        $response = $this->postJson("/api/public/sites/test-site/forms/{$fakeFormId}", [
            'fields' => ['name' => 'Test'],
        ]);

        $response->assertStatus(404);
    }

    public function test_submit_form_succeeds_with_valid_form_id(): void
    {
        $page = app(CreateWebsitePageAction::class)->execute($this->website, [
            'slug' => 'contact',
            'title' => 'Contact',
            'page_type' => 'page',
        ]);
        $page->update(['exported_html' => '<div>Contact us</div>']);

        // Inject nav+forms — this stores a form_id on the page
        app(EnhanceWebsiteNavigationAction::class)->execute($this->website);

        $this->publishWebsite();

        $page->refresh();
        $this->assertNotNull($page->form_id);

        $response = $this->postJson("/api/public/sites/test-site/forms/{$page->form_id}", [
            'fields' => ['name' => 'Alice', 'email' => 'alice@example.com'],
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_submit_form_honeypot_silently_discards_bot_submissions(): void
    {
        $page = app(CreateWebsitePageAction::class)->execute($this->website, [
            'slug' => 'contact',
            'title' => 'Contact',
            'page_type' => 'page',
        ]);
        $page->update(['exported_html' => '<div>Contact us</div>']);
        app(EnhanceWebsiteNavigationAction::class)->execute($this->website);
        $this->publishWebsite();
        $page->refresh();

        // Bot fills the honeypot field
        $response = $this->postJson("/api/public/sites/test-site/forms/{$page->form_id}", [
            'fields' => ['name' => 'SpamBot'],
            '_hp' => 'bot-was-here',
        ]);

        // Returns 200 (silent discard) — bot gets no error signal
        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_cross_team_form_id_cannot_submit_to_other_website(): void
    {
        // Team B has its own website with a form
        $teamB = Team::factory()->create();
        $websiteB = app(CreateWebsiteAction::class)->execute($teamB, [
            'name' => 'Site B',
            'slug' => 'site-b',
        ]);
        $pageB = app(CreateWebsitePageAction::class)->execute($websiteB, [
            'slug' => 'contact',
            'title' => 'Contact',
            'page_type' => 'page',
        ]);
        $pageB->update(['exported_html' => '<div>Contact</div>']);
        app(EnhanceWebsiteNavigationAction::class)->execute($websiteB);
        $pageB->refresh();

        // Publish Team A's website
        $this->publishWebsite();

        // Use Team B's form_id against Team A's website — must return 404
        $response = $this->postJson("/api/public/sites/test-site/forms/{$pageB->form_id}", [
            'fields' => ['name' => 'Attacker'],
        ]);

        $response->assertStatus(404);
    }
}
