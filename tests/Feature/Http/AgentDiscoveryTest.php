<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * Covers the agent-readiness discovery surface: sitemap, API catalog, auth.md,
 * Agent Skills index, and the A2A card's supportedInterfaces field.
 *
 * Assertions mirror what external scanners validate, so a regression here is
 * caught before it shows up as a failed check in production.
 */
class AgentDiscoveryTest extends TestCase
{
    // No RefreshDatabase — every endpoint under test is a pure render.

    public function test_sitemap_returns_valid_xml_listing_public_pages(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=utf-8');

        $body = $response->getContent();

        $xml = simplexml_load_string($body);
        $this->assertNotFalse($xml, 'sitemap.xml must be well-formed XML');
        $this->assertSame('urlset', $xml->getName());

        $this->assertStringContainsString('/docs/introduction', $body);
        $this->assertGreaterThan(5, $xml->count(), 'sitemap should list more than a handful of pages');
    }

    public function test_sitemap_excludes_auth_and_machine_surfaces(): void
    {
        $body = $this->get('/sitemap.xml')->getContent();

        $forbidden = [
            '/login', '/register', '/api/', '/.well-known/', '/livewire',
            '/authorize', '/end-session', '/webauthn', '/account',
            '.json', '.txt', '.xml', '.js',
        ];

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $body,
                "sitemap must not list {$needle}",
            );
        }
    }

    public function test_api_catalog_follows_rfc_9727(): void
    {
        $response = $this->get('/.well-known/api-catalog');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/linkset+json');

        $linkset = $response->json('linkset');

        $this->assertNotEmpty($linkset);
        $this->assertArrayHasKey('anchor', $linkset[0]);
        $this->assertNotEmpty($linkset[0]['service-desc'][0]['href']);
        $this->assertNotEmpty($linkset[0]['service-doc'][0]['href']);
    }

    public function test_auth_md_is_markdown_with_conforming_heading(): void
    {
        $response = $this->get('/auth.md');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/markdown; charset=utf-8');

        $body = $response->getContent();

        // The scanner requires an H1 containing "auth.md".
        $this->assertMatchesRegularExpression('/^#\s.*auth\.md/mi', $body);
        $this->assertStringContainsString('/.well-known/oauth-protected-resource', $body);
        $this->assertStringContainsString('agent_auth', $body);
    }

    public function test_agent_skills_index_matches_discovery_schema(): void
    {
        $response = $this->get('/.well-known/agent-skills/index.json');

        $response->assertOk();
        $response->assertJsonPath('$schema', 'https://schemas.agentskills.io/discovery/0.2.0/schema.json');

        $skills = $response->json('skills');

        $this->assertNotEmpty($skills);

        foreach ($skills as $skill) {
            foreach (['name', 'type', 'description', 'url', 'digest'] as $field) {
                $this->assertArrayHasKey($field, $skill);
                $this->assertNotEmpty($skill[$field]);
            }

            $this->assertSame('skill-md', $skill['type']);
            $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $skill['digest']);
        }
    }

    public function test_agent_skill_digest_matches_served_artifact(): void
    {
        $skills = $this->get('/.well-known/agent-skills/index.json')->json('skills');

        foreach ($skills as $skill) {
            $artifact = $this->get('/.well-known/agent-skills/'.$skill['name'].'/SKILL.md');

            $artifact->assertOk();
            $artifact->assertHeader('Content-Type', 'text/markdown; charset=utf-8');

            $this->assertSame(
                'sha256:'.hash('sha256', $artifact->getContent()),
                $skill['digest'],
                "digest mismatch for skill {$skill['name']}",
            );
        }
    }

    public function test_unknown_agent_skill_returns_404(): void
    {
        $this->get('/.well-known/agent-skills/not-a-real-skill/SKILL.md')->assertNotFound();
    }

    public function test_authorization_server_metadata_carries_agent_auth(): void
    {
        // The scanner looks for agent_auth in the AS metadata document, not in
        // auth.md itself — a fenced JSON block in the markdown does not count.
        $response = $this->get('/.well-known/oauth-authorization-server');

        $response->assertOk();

        $agentAuth = $response->json('agent_auth');

        $this->assertNotEmpty($agentAuth);
        // The scanner requires skill to resolve to the auth.md document itself.
        $this->assertStringEndsWith('/auth.md', $agentAuth['skill']);
        $this->assertNotEmpty($agentAuth['register_uri']);
        $this->assertNotEmpty($agentAuth['methods'][0]['type']);
        $this->assertNotEmpty($agentAuth['methods'][0]['register_uri']);
    }

    public function test_agent_card_declares_supported_interfaces(): void
    {
        $response = $this->get('/.well-known/agent-card.json');

        $response->assertOk();

        $interfaces = $response->json('supportedInterfaces');

        $this->assertNotEmpty($interfaces, 'A2A spec requires a non-empty supportedInterfaces');
        $this->assertNotEmpty($interfaces[0]['url']);
        $this->assertNotEmpty($interfaces[0]['protocolBinding']);
    }

    public function test_html_pages_carry_agent_discovery_link_headers(): void
    {
        $response = $this->get('/docs/introduction');

        $response->assertOk();

        $links = implode(' ', $response->headers->all('Link'));

        $this->assertStringContainsString('rel="api-catalog"', $links);
        $this->assertStringContainsString('rel="service-desc"', $links);
        $this->assertStringContainsString('rel="service-doc"', $links);
    }

    public function test_non_html_responses_do_not_get_link_headers(): void
    {
        $response = $this->get('/llms.txt');

        $response->assertOk();
        $this->assertEmpty($response->headers->all('Link'));
    }

    public function test_homepage_negotiates_markdown_for_agents(): void
    {
        $response = $this->get('/', ['Accept' => 'text/markdown']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/markdown; charset=utf-8');
        $response->assertHeader('Vary', 'Accept');
        $this->assertStringEndsWith('/llms.txt', $response->headers->get('Content-Location'));
        $this->assertStringContainsString('# FleetQ', $response->getContent());
    }

    public function test_homepage_serves_html_to_browsers(): void
    {
        $response = $this->get('/', ['Accept' => 'text/html,application/xhtml+xml']);

        // Browsers never receive markdown — status may be 200 (cloud landing) or
        // a redirect (base), but it must never be the markdown representation.
        $this->assertStringNotContainsString(
            'text/markdown',
            (string) $response->headers->get('Content-Type'),
        );
    }

    public function test_markdown_negotiation_only_applies_where_a_representation_exists(): void
    {
        // A docs page has no curated markdown source, so it falls through to HTML
        // rather than emitting a fabricated markdown body.
        $response = $this->get('/docs/introduction', ['Accept' => 'text/markdown']);

        $response->assertOk();
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
    }
}
