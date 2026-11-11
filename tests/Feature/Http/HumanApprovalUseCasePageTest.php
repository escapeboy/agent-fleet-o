<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * Covers the public /use-cases/human-approval-and-budgets-for-ai-agents SEO
 * page: routing, head tags, headings, JSON-LD, CTAs, sitemap and internal links.
 */
class HumanApprovalUseCasePageTest extends TestCase
{
    // No RefreshDatabase — every page under test is a pure render.

    private const PATH = '/use-cases/human-approval-and-budgets-for-ai-agents';

    private const TITLE = 'Human approval and budget limits for AI agents in production | FleetQ';

    private const DESCRIPTION = 'Stop runaway agent costs and unreviewed actions. Set budgets, require human approval and keep an audit trail in one place. Self-host free or use Cloud.';

    private function html(): string
    {
        return $this->get(self::PATH)->assertOk()->getContent();
    }

    private static function decode(string $s): string
    {
        return html_entity_decode($s, ENT_QUOTES | ENT_HTML5);
    }

    public function test_page_returns_200(): void
    {
        $this->get(self::PATH)->assertOk();
    }

    public function test_page_has_exact_title_description_and_canonical(): void
    {
        $html = $this->html();

        $this->assertSame(1, preg_match('#<title>(.*?)</title>#s', $html, $m));
        $this->assertSame(self::TITLE, self::decode(trim($m[1])));

        $this->assertSame(1, preg_match('#<meta name="description" content="([^"]*)"#', $html, $d));
        $this->assertSame(self::DESCRIPTION, self::decode($d[1]));

        $url = url(self::PATH);
        $this->assertStringContainsString('<link rel="canonical" href="'.$url.'">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="'.$url.'">', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $html);
    }

    public function test_page_has_one_h1_and_the_expected_h2s(): void
    {
        $html = $this->html();

        $this->assertSame(1, preg_match_all('#<h1[^>]*>(.*?)</h1>#s', $html, $h1));
        $this->assertSame(
            'Human approval and budget limits for AI agents in production',
            self::decode(trim(strip_tags($h1[1][0]))),
        );

        preg_match_all('#<h2[^>]*>(.*?)</h2>#s', $html, $h2);
        $headings = array_map(fn ($h) => self::decode(trim(strip_tags($h))), $h2[1]);

        $expected = [
            'The three failure modes we see',
            'How FleetQ handles it',
            'Compared with building it yourself',
            'Try it',
            'FAQ',
        ];
        $positions = [];
        foreach ($expected as $heading) {
            $pos = array_search($heading, $headings, true);
            $this->assertNotFalse($pos, "Missing H2: {$heading}");
            $positions[] = $pos;
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'H2s are out of order');
    }

    public function test_page_has_valid_faqpage_and_breadcrumb_json_ld(): void
    {
        $html = $this->html();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);
        $this->assertNotEmpty($matches[1]);

        $faqs = [];
        $breadcrumb = null;
        foreach ($matches[1] as $raw) {
            $decoded = json_decode($raw, true);
            $this->assertIsArray($decoded, 'JSON-LD must decode');
            if (($decoded['@type'] ?? null) === 'FAQPage') {
                $faqs[] = $decoded;
            }
            if (($decoded['@type'] ?? null) === 'BreadcrumbList') {
                $breadcrumb = $decoded;
            }
        }

        $this->assertCount(1, $faqs);
        $this->assertCount(4, $faqs[0]['mainEntity']);

        $visible = self::decode($html);
        foreach ($faqs[0]['mainEntity'] as $q) {
            $this->assertSame('Question', $q['@type']);
            $this->assertNotEmpty($q['name']);
            $this->assertNotEmpty($q['acceptedAnswer']['text']);
            $this->assertMatchesRegularExpression(
                '#<dt[^>]*>\s*'.preg_quote($q['name'], '#').'\s*</dt>#',
                $visible,
            );
        }

        $this->assertNotNull($breadcrumb, 'BreadcrumbList JSON-LD missing');
        $this->assertSame(route('use-cases.index'), $breadcrumb['itemListElement'][0]['item']);
    }

    public function test_page_ctas_point_to_signup_and_github(): void
    {
        $html = $this->html();

        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('register'), '#').'"[^>]*data-analytics-event="cta_click"[^>]*data-analytics-label="try_cloud"#s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '#<a href="https://github\.com/escapeboy/agent-fleet-o"[^>]*data-analytics-event="cta_click"[^>]*data-analytics-label="self_host_github"#s',
            $html,
        );
        $this->assertStringContainsString('href="'.route('compare.index').'"', $html);
        $this->assertStringContainsString('href="'.route('docs.show', 'mcp-server').'"', $html);
    }

    public function test_sitemap_lists_the_page(): void
    {
        $body = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertNotFalse(simplexml_load_string($body), 'sitemap.xml must be well-formed XML');
        $this->assertStringContainsString('<loc>'.url(self::PATH).'</loc>', $body);
    }

    public function test_use_cases_index_links_to_page(): void
    {
        $this->get('/use-cases')->assertOk()->assertSee('href="'.url(self::PATH).'"', false);
    }

    public function test_homepage_control_card_links_to_page(): void
    {
        $this->blade('<x-landing.features />')->assertSee('href="'.url(self::PATH).'"', false);
    }

    public function test_existing_slug_routes_unaffected(): void
    {
        $this->get('/use-cases/content-marketing')->assertOk();
        $this->get('/use-cases/does-not-exist')->assertNotFound();
    }
}
