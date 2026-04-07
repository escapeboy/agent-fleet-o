<?php

namespace Tests\Feature\Website;

use App\Domain\Shared\Models\Team;
use App\Domain\Website\Actions\CreateWebsiteAction;
use App\Domain\Website\Actions\CreateWebsitePageAction;
use App\Domain\Website\Actions\EnhanceWebsiteNavigationAction;
use App\Domain\Website\Actions\UpdateWebsitePageAction;
use App\Domain\Website\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnhanceWebsiteNavigationTest extends TestCase
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

    private function createPage(string $slug, string $title, string $html, string $pageType = 'page'): void
    {
        $page = app(CreateWebsitePageAction::class)->execute($this->website, [
            'slug' => $slug,
            'title' => $title,
            'page_type' => $pageType,
        ]);

        app(UpdateWebsitePageAction::class)->execute($page, ['exported_html' => $html]);
    }

    public function test_injects_navigation_when_no_nav_present(): void
    {
        $this->createPage('index', 'Home', '<div>Welcome</div>');
        $this->createPage('about', 'About', '<div>About us</div>');

        app(EnhanceWebsiteNavigationAction::class)->execute($this->website);

        $this->website->load('pages');
        foreach ($this->website->pages as $page) {
            $this->assertStringContainsString('<nav', $page->exported_html);
            $this->assertStringContainsString('href="/index"', $page->exported_html);
            $this->assertStringContainsString('href="/about"', $page->exported_html);
        }
    }

    public function test_replaces_existing_nav_with_correct_links(): void
    {
        $this->createPage('index', 'Home', '<nav><a href="#">Old link</a></nav><div>Content</div>');
        $this->createPage('contact', 'Contact', '<div>Contact page</div>');

        app(EnhanceWebsiteNavigationAction::class)->execute($this->website);

        $this->website->load('pages');
        $homePage = $this->website->pages->firstWhere('slug', 'index');

        $this->assertStringNotContainsString('Old link', $homePage->exported_html);
        $this->assertStringContainsString('href="/contact"', $homePage->exported_html);
    }

    public function test_nav_appears_before_page_content(): void
    {
        $this->createPage('index', 'Home', '<div>Content here</div>');

        app(EnhanceWebsiteNavigationAction::class)->execute($this->website);

        $this->website->load('pages');
        $page = $this->website->pages->first();

        // Nav should be at the very start (prepended) or at least before the div content
        $navPos = strpos($page->exported_html, '<nav');
        $divPos = strpos($page->exported_html, '<div>');

        $this->assertNotFalse($navPos, 'Nav element must exist in HTML');
        $this->assertNotFalse($divPos, 'Content div must exist in HTML');
        $this->assertLessThan($divPos, $navPos, 'Nav must appear before page content');
    }

    public function test_injects_contact_form_on_landing_page(): void
    {
        $this->createPage('index', 'Home', '<div>Welcome</div>', 'landing');

        app(EnhanceWebsiteNavigationAction::class)->execute($this->website);

        $this->website->load('pages');
        $page = $this->website->pages->first();

        $this->assertStringContainsString('<form', $page->exported_html);
        $this->assertStringContainsString('method="POST"', $page->exported_html);
        $this->assertStringContainsString('/api/public/sites/test-site/forms/', $page->exported_html);
    }

    public function test_injects_contact_form_when_page_mentions_contact(): void
    {
        $this->createPage('contact', 'Contact', '<div><h1>Contact us</h1></div>');

        app(EnhanceWebsiteNavigationAction::class)->execute($this->website);

        $this->website->load('pages');
        $page = $this->website->pages->first();

        $this->assertStringContainsString('<form', $page->exported_html);
        $this->assertStringContainsString('fields[name]', $page->exported_html);
        $this->assertStringContainsString('fields[email]', $page->exported_html);
        $this->assertStringContainsString('fields[message]', $page->exported_html);
    }

    public function test_does_not_inject_form_on_regular_page(): void
    {
        $this->createPage('about', 'About', '<div><h1>About our team</h1><p>We are developers.</p></div>');

        app(EnhanceWebsiteNavigationAction::class)->execute($this->website);

        $this->website->load('pages');
        $page = $this->website->pages->first();

        $this->assertStringNotContainsString('<form', $page->exported_html);
    }

    public function test_does_not_inject_duplicate_form_when_api_form_already_injected(): void
    {
        // Simulate a page that already has an injected /api/public/ form (second run idempotency)
        $html = '<div>Contact us</div>'
            .'<form method="POST" action="/api/public/sites/test-site/forms/some-uuid">'
            .'<input name="fields[name]"><button type="submit">Send</button></form>';

        $page = app(CreateWebsitePageAction::class)->execute($this->website, [
            'slug' => 'contact',
            'title' => 'Contact',
            'page_type' => 'page',
        ]);
        // Directly set exported_html — bypasses sanitizer since action is already server-generated
        $page->update(['exported_html' => $html]);

        app(EnhanceWebsiteNavigationAction::class)->execute($this->website);

        $this->website->load('pages');
        $savedPage = $this->website->pages->firstWhere('slug', 'contact');

        // Should not add a second form — the /api/public/ form is already present
        $this->assertSame(1, substr_count($savedPage->exported_html, '<form'));
    }

    public function test_reinjects_form_when_action_was_stripped_by_sanitizer(): void
    {
        // Simulate what happens after sanitizer strips form[action] from AI-generated HTML
        // The form element exists but has no action (sanitizer stripped it)
        $html = '<div>Contact us</div><form method="POST"><input name="test"></form>';

        $page = app(CreateWebsitePageAction::class)->execute($this->website, [
            'slug' => 'contact',
            'title' => 'Contact',
            'page_type' => 'page',
        ]);
        $page->update(['exported_html' => $html]);

        app(EnhanceWebsiteNavigationAction::class)->execute($this->website);

        $this->website->load('pages');
        $savedPage = $this->website->pages->firstWhere('slug', 'contact');

        // Should inject a proper form with /api/public/sites/ action
        $this->assertStringContainsString('/api/public/sites/test-site/forms/', $savedPage->exported_html);
    }

    public function test_empty_website_does_nothing(): void
    {
        // No pages — should not throw
        app(EnhanceWebsiteNavigationAction::class)->execute($this->website);

        $this->website->load('pages');
        $this->assertCount(0, $this->website->pages);
    }
}
