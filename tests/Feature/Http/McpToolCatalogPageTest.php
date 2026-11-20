<?php

namespace Tests\Feature\Http;

use App\Mcp\Services\McpToolCatalog;
use App\Mcp\Tools\Admin\AdminBillingRefundTool;
use App\Mcp\Tools\Admin\AdminTeamSuspendTool;
use App\Mcp\Tools\Agent\AgentCreateTool;
use Illuminate\Support\Facades\Cache;
use ReflectionClass;
use Tests\TestCase;

/**
 * Covers the public /mcp-tools catalog page: SEO head, listed tools,
 * admin exclusion, CTAs, sitemap entry and internal links.
 */
class McpToolCatalogPageTest extends TestCase
{
    // No RefreshDatabase — the page is a pure render.

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guest_gets_200_with_seo_head(): void
    {
        $html = (string) $this->get('/mcp-tools')->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<title>(.*?)</title>#s', $html, $m));
        $title = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
        $this->assertNotSame('', $title);
        $this->assertLessThan(60, mb_strlen($title));

        preg_match('#<meta name="description" content="([^"]*)"#', $html, $d);
        $this->assertNotEmpty($d[1] ?? '');
        $this->assertLessThan(155, mb_strlen(html_entity_decode($d[1], ENT_QUOTES | ENT_HTML5)));

        $this->assertStringContainsString('<link rel="canonical" href="'.url('/mcp-tools').'">', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $html);
    }

    public function test_lists_known_tool_with_description_and_group(): void
    {
        $response = $this->get('/mcp-tools')->assertOk();

        $description = (new ReflectionClass(AgentCreateTool::class))->getDefaultProperties()['description'];
        $this->assertIsString($description);

        $response->assertSee('agent_create');
        $response->assertSee($description);
        $this->assertMatchesRegularExpression('#<h2[^>]*>\s*Agent\s*<#', (string) $response->getContent());
    }

    public function test_does_not_list_admin_tools(): void
    {
        $html = (string) $this->get('/mcp-tools')->assertOk()->getContent();

        $this->assertStringNotContainsString('admin_', $html);
        foreach ([AdminTeamSuspendTool::class, AdminBillingRefundTool::class] as $class) {
            $name = (new ReflectionClass($class))->getDefaultProperties()['name'];
            $this->assertIsString($name);
            $this->assertNotSame('', $name);
            $this->assertStringNotContainsString($name, $html);
        }
    }

    public function test_has_cta_and_docs_links(): void
    {
        $html = (string) $this->get('/mcp-tools')->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('register').'"', $html);
        $this->assertStringContainsString('https://github.com/escapeboy/agent-fleet-o', $html);
        $this->assertStringContainsString('href="'.route('docs.show', 'mcp-server').'"', $html);
        $this->assertStringContainsString('data-analytics-label="try_cloud"', $html);
        $this->assertStringContainsString('data-analytics-label="self_host_github"', $html);
    }

    public function test_sitemap_lists_mcp_tools_page(): void
    {
        $body = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertNotFalse(simplexml_load_string($body));
        $this->assertStringContainsString('<loc>'.url('/mcp-tools').'</loc>', $body);
        $this->assertStringNotContainsString('<loc>'.url('/mcp').'</loc>', $body);
    }

    public function test_footer_and_mcp_docs_link_to_catalog(): void
    {
        $this->blade('<x-landing.footer />')->assertSee(route('mcp-tools.index'), false);

        $this->get('/docs/mcp-server')->assertOk()->assertSee(route('mcp-tools.index'), false);
    }

    public function test_service_groups_exclude_admin_and_are_cached(): void
    {
        Cache::forget('mcp-tool-catalog');

        $groups = app(McpToolCatalog::class)->groups();

        $this->assertNotEmpty($groups);
        $names = [];
        foreach ($groups as $group) {
            $this->assertNotSame('', $group['group']);
            $this->assertNotSame('Admin', $group['group']);
            foreach ($group['tools'] as $tool) {
                $this->assertNotSame('', $tool['name']);
                $names[] = $tool['name'];
            }
        }
        $this->assertContains('agent_create', $names);
        $this->assertSame(count($names), count(array_unique($names)));

        $adminNames = [];
        foreach ([AdminTeamSuspendTool::class, AdminBillingRefundTool::class] as $class) {
            $adminNames[] = (new ReflectionClass($class))->getDefaultProperties()['name'];
        }
        $this->assertSame([], array_values(array_intersect($adminNames, $names)));
        $this->assertSame(count($names), app(McpToolCatalog::class)->total());

        $spy = Cache::spy();
        app(McpToolCatalog::class)->groups();
        $spy->shouldHaveReceived('remember')->withArgs(fn ($key) => $key === 'mcp-tool-catalog');
    }
}
