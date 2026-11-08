<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the public /templates SEO gallery: guest access, CTAs, 404s,
 * sitemap entries and the inbound links from /use-cases and /marketplace.
 */
class PublicAgentTemplatesPageTest extends TestCase
{
    // RefreshDatabase because the marketplace page queries listings.
    use RefreshDatabase;

    private const SLUG = 'qa-engineer';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guest_can_view_templates_index(): void
    {
        $response = $this->get('/templates');

        $response->assertOk();
        foreach (config('agent-templates') as $template) {
            $response->assertSee($template['name']);
        }
        $response->assertSee('href="'.url('/templates/'.self::SLUG).'"', false);
    }

    public function test_guest_can_view_template_detail(): void
    {
        $template = $this->template();

        $response = $this->get('/templates/'.self::SLUG);

        $response->assertOk()
            ->assertSee($template['name'])
            ->assertSee($template['role'])
            ->assertSee($template['goal'])
            ->assertSee('Use this template');
        foreach ($template['skills'] as $skill) {
            $response->assertSee($skill);
        }
        $response->assertSee('href="'.route('agents.create', ['template' => self::SLUG]).'"', false);
        $response->assertSee('href="'.route('docs.show', 'getting-started').'"', false);
        $response->assertDontSee($template['model']);
    }

    public function test_every_listed_template_page_renders(): void
    {
        foreach (config('agent-templates') as $template) {
            $this->get('/templates/'.$template['slug'])->assertOk()->assertSee($template['name']);
        }
    }

    public function test_guest_following_use_this_template_is_sent_to_login(): void
    {
        $this->get(route('agents.create', ['template' => self::SLUG]))->assertRedirect(route('login'));
    }

    public function test_unknown_template_slug_returns_404(): void
    {
        $this->get('/templates/does-not-exist')->assertNotFound();
    }

    public function test_sitemap_lists_template_index_and_detail_pages(): void
    {
        $body = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<loc>'.url('/templates').'</loc>', $body);
        foreach (config('agent-templates') as $template) {
            $this->assertStringContainsString('<loc>'.url('/templates/'.$template['slug']).'</loc>', $body);
        }
    }

    public function test_use_cases_and_marketplace_link_to_templates_gallery(): void
    {
        $href = 'href="'.url('/templates').'"';

        $this->get('/use-cases')->assertOk()->assertSee($href, false);
        $this->get('/marketplace')->assertOk()->assertSee($href, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function template(): array
    {
        return collect(config('agent-templates'))->firstWhere('slug', self::SLUG);
    }
}
