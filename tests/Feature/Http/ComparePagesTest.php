<?php

namespace Tests\Feature\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the public /compare SEO pages: routing, head tags, JSON-LD,
 * comparison table, CTAs, body length, sitemap and internal links.
 */
class ComparePagesTest extends TestCase
{
    // No RefreshDatabase — every page under test is a pure render.

    private const SLUGS = ['langgraph', 'crewai', 'n8n'];

    /**
     * @return array<string, array{string}>
     */
    public static function slugProvider(): array
    {
        return [
            'langgraph' => ['langgraph'],
            'crewai' => ['crewai'],
            'n8n' => ['n8n'],
        ];
    }

    public function test_compare_index_returns_200_and_links_all_three_pages(): void
    {
        $response = $this->get('/compare');

        $response->assertOk();
        foreach (self::SLUGS as $slug) {
            $response->assertSee('href="'.url('/compare/'.$slug).'"', false);
        }
    }

    #[DataProvider('slugProvider')]
    public function test_each_compare_page_renders_seo_head(string $slug): void
    {
        $response = $this->get('/compare/'.$slug);
        $response->assertOk();
        $html = $response->getContent();

        $expectedTitle = config("comparisons.{$slug}.meta_title");
        $this->assertIsString($expectedTitle);
        $this->assertLessThan(60, mb_strlen($expectedTitle));
        $this->assertMatchesRegularExpression('#<title>(.*?)</title>#s', $html);
        preg_match('#<title>(.*?)</title>#s', $html, $m);
        $this->assertSame($expectedTitle, html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5));

        preg_match('#<meta name="description" content="([^"]*)"#', $html, $d);
        $this->assertNotEmpty($d[1] ?? '');
        $this->assertLessThan(155, mb_strlen(html_entity_decode($d[1], ENT_QUOTES | ENT_HTML5)));

        $url = url('/compare/'.$slug);
        $this->assertStringContainsString('<link rel="canonical" href="'.$url.'">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="'.$url.'">', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $html);
    }

    public function test_unknown_compare_slug_returns_404(): void
    {
        $this->get('/compare/zapier')->assertNotFound();
    }

    #[DataProvider('slugProvider')]
    public function test_compare_page_has_valid_faqpage_json_ld(string $slug): void
    {
        $html = $this->get('/compare/'.$slug)->assertOk()->getContent();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);
        $this->assertNotEmpty($matches[1]);

        $types = [];
        $faq = null;
        foreach ($matches[1] as $raw) {
            $decoded = json_decode($raw, true);
            $this->assertIsArray($decoded, 'JSON-LD must decode');
            $types[] = $decoded['@type'] ?? null;
            if (($decoded['@type'] ?? null) === 'FAQPage') {
                $faq = $decoded;
            }
        }

        $this->assertNotNull($faq, 'FAQPage JSON-LD missing');
        $this->assertCount(3, $faq['mainEntity']);
        foreach ($faq['mainEntity'] as $q) {
            $this->assertSame('Question', $q['@type']);
            $this->assertNotEmpty($q['name']);
            $this->assertNotEmpty($q['acceptedAnswer']['text']);
        }
        $this->assertContains('BreadcrumbList', $types);
    }

    #[DataProvider('slugProvider')]
    public function test_compare_page_has_comparison_table_rows_and_checked_on_note(string $slug): void
    {
        $response = $this->get('/compare/'.$slug)->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('<table', $html);
        foreach ([
            'Focus', 'Self-hosting', 'Human approvals', 'Budget limits',
            'Audit trail', 'MCP server', 'Bring-your-own LLM keys', 'Pricing model',
        ] as $label) {
            $this->assertMatchesRegularExpression('#<th scope="row"[^>]*>\s*'.preg_quote($label, '#').'\s*</th>#', $html);
        }
        $this->assertStringContainsString('AGPL-3.0', $html);
        $response->assertSee('Checked on '.config('comparisons.checked_on'));
        $this->assertCount(8, config("comparisons.{$slug}.table"));
    }

    #[DataProvider('slugProvider')]
    public function test_compare_page_has_cta_and_docs_links(string $slug): void
    {
        $html = $this->get('/compare/'.$slug)->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-analytics-event="cta_click"'));
        $this->assertStringContainsString('data-analytics-label="self_host_github"', $html);
        $this->assertStringContainsString('data-analytics-label="try_cloud"', $html);
        $this->assertStringContainsString('https://github.com/escapeboy/agent-fleet-o', $html);
        $this->assertStringContainsString('href="'.route('docs.show', 'mcp-server').'"', $html);
        $this->assertStringContainsString('href="'.route('docs.show', 'agents').'"', $html);
    }

    #[DataProvider('slugProvider')]
    public function test_compare_page_body_is_700_to_1100_words(string $slug): void
    {
        $html = $this->get('/compare/'.$slug)->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<main[^>]*>(.*)</main>#s', $html, $m));
        $text = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5);
        $words = str_word_count($text);

        $this->assertGreaterThanOrEqual(700, $words, "{$slug} body has {$words} words");
        $this->assertLessThanOrEqual(1100, $words, "{$slug} body has {$words} words");
    }

    public function test_sitemap_lists_compare_pages(): void
    {
        $body = $this->get('/sitemap.xml')->assertOk()->getContent();

        $xml = simplexml_load_string($body);
        $this->assertNotFalse($xml, 'sitemap.xml must be well-formed XML');

        $this->assertStringContainsString('<loc>'.url('/compare').'</loc>', $body);
        foreach (self::SLUGS as $slug) {
            $this->assertStringContainsString('<loc>'.url('/compare/'.$slug).'</loc>', $body);
        }
    }

    public function test_footer_and_use_cases_link_to_compare(): void
    {
        $this->blade('<x-landing.footer />')->assertSee(route('compare.index'), false);

        $this->get('/use-cases')->assertOk()->assertSee(route('compare.index'), false);
    }
}
