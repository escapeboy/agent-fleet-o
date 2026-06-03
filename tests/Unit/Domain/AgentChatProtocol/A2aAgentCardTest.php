<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\AgentChatProtocol;

use App\Domain\AgentChatProtocol\DTOs\A2aAgentCard;
use App\Domain\AgentChatProtocol\Exceptions\A2aDiscoveryException;
use PHPUnit\Framework\TestCase;

class A2aAgentCardTest extends TestCase
{
    /** A2A AgentCard, newer spec shape: supportedInterfaces + securitySchemes. */
    public function test_parses_new_shape_with_supported_interfaces(): void
    {
        $card = A2aAgentCard::fromArray([
            'name' => 'GeoRoute Agent',
            'description' => 'Route planning.',
            'version' => '1.2.0',
            'supportedInterfaces' => [
                ['url' => 'https://georoute.example.com/a2a/v1', 'protocolBinding' => 'JSONRPC'],
                ['url' => 'https://georoute.example.com/a2a/grpc', 'protocolBinding' => 'GRPC'],
            ],
            'securitySchemes' => ['Bearer' => ['type' => 'http'], 'OAuth2' => ['type' => 'oauth2']],
            'capabilities' => ['streaming' => true, 'pushNotifications' => true],
            'skills' => [
                ['id' => 'route', 'name' => 'Plan Route', 'description' => 'Plans a route'],
            ],
            'provider' => ['organization' => 'Example Geo'],
            'defaultInputModes' => ['text'],
            'defaultOutputModes' => ['text', 'structured'],
        ]);

        $this->assertSame('GeoRoute Agent', $card->name);
        // First supportedInterfaces entry wins.
        $this->assertSame('https://georoute.example.com/a2a/v1', $card->endpointUrl);
        $this->assertSame(['Bearer', 'OAuth2'], $card->securitySchemes);

        $caps = $card->toCapabilities();
        $this->assertSame('a2a', $caps['source']);
        $this->assertSame('1.2.0', $caps['agent_version']);
        $this->assertTrue($caps['streaming']);
        $this->assertTrue($caps['push_notifications']);
        $this->assertSame(1, $caps['skill_count']);
        $this->assertSame(['Bearer', 'OAuth2'], $caps['security_schemes']);

        $this->assertSame(['organization' => 'Example Geo'], $card->provider);
        $this->assertCount(1, $card->toMetadata()['a2a']['skills']);
    }

    /** A2A AgentCard, older shape: top-level url + authentication.schemes (FleetQ's own card uses this). */
    public function test_parses_old_shape_with_top_level_url_and_authentication(): void
    {
        $card = A2aAgentCard::fromArray([
            'name' => 'FleetQ',
            'description' => 'AI Agent Mission Control.',
            'url' => 'https://app.fleetq.test/mcp',
            'version' => '1.0.0',
            'authentication' => ['schemes' => ['Bearer']],
            'capabilities' => ['streaming' => false, 'stateTransitionHistory' => true],
            'skills' => [
                ['id' => 'run_experiment', 'name' => 'Run Experiment'],
                ['id' => 'manage_workflow', 'name' => 'Manage Workflow'],
            ],
        ]);

        $this->assertSame('FleetQ', $card->name);
        $this->assertSame('https://app.fleetq.test/mcp', $card->endpointUrl);
        $this->assertSame(['Bearer'], $card->securitySchemes);

        $caps = $card->toCapabilities();
        $this->assertFalse($caps['streaming']);
        $this->assertTrue($caps['state_transition_history']);
        $this->assertSame(2, $caps['skill_count']);
    }

    public function test_throws_when_name_missing(): void
    {
        $this->expectException(A2aDiscoveryException::class);

        A2aAgentCard::fromArray([
            'description' => 'No name here.',
            'url' => 'https://x.example.com',
        ]);
    }

    public function test_throws_when_description_missing(): void
    {
        $this->expectException(A2aDiscoveryException::class);

        A2aAgentCard::fromArray([
            'name' => 'Nameless desc',
            'url' => 'https://x.example.com',
        ]);
    }

    public function test_throws_when_no_endpoint_present(): void
    {
        $this->expectException(A2aDiscoveryException::class);

        A2aAgentCard::fromArray([
            'name' => 'No endpoint',
            'description' => 'Has no url or supportedInterfaces.',
        ]);
    }

    /** Whitespace-only required fields are treated as missing. */
    public function test_throws_when_name_is_blank(): void
    {
        $this->expectException(A2aDiscoveryException::class);

        A2aAgentCard::fromArray([
            'name' => '   ',
            'description' => 'Blank name.',
            'url' => 'https://x.example.com',
        ]);
    }

    public function test_raw_document_is_preserved(): void
    {
        $raw = [
            'name' => 'Echo',
            'description' => 'Echoes input.',
            'url' => 'https://echo.example.com',
            'extra_vendor_field' => ['anything' => 1],
        ];

        $card = A2aAgentCard::fromArray($raw);

        $this->assertSame($raw, $card->raw);
    }
}
