<?php

namespace Tests\Feature\Http;

use App\Mcp\Services\McpToolCatalog;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Covers the computed MCP tool count shown on the landing page and docs.
 */
class McpToolCountDisplayTest extends TestCase
{
    // No RefreshDatabase — these are pure renders.

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function cacheFakeTools(int $count): void
    {
        $groups = [];
        $perGroup = 100;
        for ($g = 0; $g * $perGroup < $count; $g++) {
            $tools = [];
            for ($i = $g * $perGroup; $i < min($count, ($g + 1) * $perGroup); $i++) {
                $tools[] = ['name' => 'fake_tool_'.$i, 'description' => 'Fake tool '.$i];
            }
            $groups[] = ['group' => 'Group '.$g, 'slug' => 'group-'.$g, 'tools' => $tools];
        }

        Cache::put('mcp-tool-catalog', $groups, 3600);
    }

    public function test_display_count_rounds_down_to_nearest_ten(): void
    {
        $catalog = app(McpToolCatalog::class);

        $this->cacheFakeTools(673);
        $this->assertSame('670+', $catalog->displayCount());

        $this->cacheFakeTools(7);
        $this->assertSame('7', $catalog->displayCount());

        $this->cacheFakeTools(680);
        $this->assertSame('680+', $catalog->displayCount());
    }

    public function test_landing_page_uses_computed_count_and_links_catalog(): void
    {
        $this->cacheFakeTools(1234);

        $html = view('landing')->render();

        $this->assertStringNotContainsString('670+', $html);
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $jsonLd = implode("\n", $m[1]);
        $this->assertStringContainsString('MCP) server with 1230+ tools', $jsonLd);

        $href = preg_quote(route('mcp-tools.index'), '#');
        $this->assertMatchesRegularExpression('#<a href="'.$href.'"[^>]*>\s*1230\+ MCP tools\s*</a>\s*for AI agent access#', $html);
    }

    public function test_landing_components_use_computed_count(): void
    {
        $this->cacheFakeTools(1234);

        $this->blade('<x-landing.faq />')
            ->assertSee('1230+')
            ->assertDontSee('670+');

        $this->blade('<x-landing.features />')
            ->assertSee('1230+')
            ->assertDontSee('670+');
    }

    public function test_docs_pages_use_computed_count(): void
    {
        $this->cacheFakeTools(1234);

        foreach (['/docs/getting-started', '/docs/mcp-server'] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('1230+', $html, $url);
            $this->assertStringNotContainsString('670+', $html, $url);
        }
    }
}
