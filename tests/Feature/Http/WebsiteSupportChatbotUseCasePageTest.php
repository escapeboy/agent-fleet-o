<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * Covers the public /use-cases/website-support-chatbot page and its links
 * from the use-case index, the chatbots docs page and related use cases.
 */
class WebsiteSupportChatbotUseCasePageTest extends TestCase
{
    // No RefreshDatabase — every page under test is a pure render.

    private const SLUG = 'website-support-chatbot';

    private const PATH = '/use-cases/website-support-chatbot';

    private const TITLE = 'Website Support Chatbot That Answers From Your Own Docs | FleetQ';

    private const DESCRIPTION = 'Deploy a support chatbot that answers from your documents, URLs, sitemaps, websites and git repos. Web widget, Slack, Telegram and tickets. Self-host free.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function html(): string
    {
        return (string) $this->get(self::PATH)->assertOk()->getContent();
    }

    private static function decode(string $s): string
    {
        return html_entity_decode($s, ENT_QUOTES | ENT_HTML5);
    }

    public function test_page_returns_200(): void
    {
        $this->get(self::PATH)->assertOk();
    }

    public function test_page_has_title_description_and_canonical(): void
    {
        $html = $this->html();

        $this->assertSame(1, preg_match('#<title>(.*?)</title>#s', $html, $m));
        $this->assertSame(self::TITLE, self::decode(trim($m[1])));

        $this->assertSame(1, preg_match('#<meta name="description" content="([^"]*)"#', $html, $d));
        $this->assertSame(self::DESCRIPTION, self::decode($d[1]));

        $this->assertStringContainsString('<link rel="canonical" href="'.url(self::PATH).'">', $html);
    }

    public function test_page_names_existing_knowledge_sources_and_review_queue(): void
    {
        $text = strtolower(self::decode(strip_tags($this->html())));

        $this->assertStringContainsString('git repositories', $text);
        $this->assertStringContainsString('sitemaps', $text);
        $this->assertStringContainsString('websites', $text);
        $this->assertStringContainsString('answer cache', $text);
        $this->assertStringContainsString('approval inbox', $text);
        $this->assertStringContainsString('pending-review queue', $text);
    }

    public function test_page_does_not_claim_an_unbuilt_learning_loop(): void
    {
        // Corrections are only captured as pending-review learning entries;
        // no code accepts them or feeds them back into answers.
        $text = strtolower(self::decode(strip_tags($this->html())));

        $this->assertStringNotContainsString('bot learns', $text);
        $this->assertStringNotContainsString('accepted corrections', $text);
        $this->assertStringNotContainsString('accepts or rejects', $text);
        $this->assertStringNotContainsString('100%', $text);
    }

    public function test_page_has_signup_self_host_and_docs_links(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('href="'.route('register').'"', $html);
        $this->assertStringContainsString('href="https://github.com/escapeboy/agent-fleet-o"', $html);
        $this->assertStringContainsString('href="'.route('docs.show', 'chatbots').'"', $html);
        $this->assertStringContainsString('Read the setup guide', $html);
        $this->assertStringContainsString('Self-host from GitHub', $html);
    }

    public function test_faqpage_json_ld_matches_config_faqs(): void
    {
        $html = $this->html();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

        $faqs = [];
        foreach ($matches[1] as $raw) {
            $decoded = json_decode($raw, true);
            $this->assertIsArray($decoded, 'JSON-LD must decode');
            if (($decoded['@type'] ?? null) === 'FAQPage') {
                $faqs[] = $decoded;
            }
        }

        $configFaqs = config('use_cases.'.self::SLUG.'.faqs');
        $this->assertIsArray($configFaqs);
        $this->assertCount(1, $faqs);
        $this->assertCount(count($configFaqs), $faqs[0]['mainEntity']);

        $visible = self::decode($html);
        foreach ($configFaqs as $faq) {
            $this->assertStringContainsString($faq['question'], $visible);
        }
    }

    public function test_use_cases_index_links_to_page(): void
    {
        $this->get('/use-cases')->assertOk()
            ->assertSee('href="'.route('use-cases.show', self::SLUG).'"', false);
    }

    public function test_chatbots_docs_link_to_page(): void
    {
        $this->get('/docs/chatbots')->assertOk()
            ->assertSee('href="'.route('use-cases.show', self::SLUG).'"', false);
    }

    public function test_customer_support_page_relates_to_new_page(): void
    {
        $html = (string) $this->get('/use-cases/customer-support')->assertOk()->getContent();

        $pos = strpos($html, 'Related Use Cases');
        $this->assertNotFalse($pos);
        $this->assertStringContainsString(
            'href="'.route('use-cases.show', self::SLUG).'"',
            substr($html, $pos),
        );
    }

    public function test_other_use_case_pages_unchanged(): void
    {
        $html = (string) $this->get('/use-cases/content-marketing')->assertOk()->getContent();

        $this->assertStringNotContainsString('Read the setup guide', $html);
        $this->assertStringNotContainsString('Self-host from GitHub', $html);

        $this->get('/use-cases/does-not-exist')->assertNotFound();
    }
}
