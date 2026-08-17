<?php

namespace Tests\Feature\Mcp;

use App\Http\Middleware\NegotiateMcpProtocol;
use App\Mcp\Protocol\ProtocolContext;
use App\Mcp\Protocol\ProtocolVersions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Tests\TestCase;

/**
 * MCP spec 2026-07-28 dual-format negotiation (SEP-2575 / 2567 / 2243).
 */
class NegotiateMcpProtocolTest extends TestCase
{
    protected function tearDown(): void
    {
        app()->forgetInstance(ProtocolContext::BINDING);

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function request(array $body, array $headers = []): Request
    {
        $request = Request::create(
            '/mcp',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body),
        );

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    private function pass(Request $request): SymfonyResponse
    {
        return (new NegotiateMcpProtocol)->handle($request, fn () => new Response('ok'));
    }

    // ── SEP-2575: negotiation data in params._meta ───────────────────────────

    public function test_parses_protocol_version_and_client_info_from_meta(): void
    {
        $request = $this->request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [
                '_meta' => [
                    ProtocolVersions::META_PROTOCOL_VERSION => ProtocolVersions::V2026_07_28,
                    ProtocolVersions::META_CLIENT_INFO => ['name' => 'acme-agent', 'version' => '2.0'],
                    ProtocolVersions::META_CLIENT_CAPABILITIES => ['elicitation' => []],
                ],
            ],
        ]);

        $response = $this->pass($request);

        $this->assertEquals(200, $response->getStatusCode());

        $context = ProtocolContext::current();
        $this->assertSame(ProtocolVersions::V2026_07_28, $context->version);
        $this->assertTrue($context->stateless);
        $this->assertSame('acme-agent', $context->clientInfo['name']);
        $this->assertSame(['elicitation' => []], $context->clientCapabilities);
    }

    public function test_serves_a_stateless_request_with_no_initialize_and_no_session(): void
    {
        $request = $this->request([
            'jsonrpc' => '2.0',
            'id' => 'abc',
            'method' => 'tools/call',
            'params' => [
                'name' => 'agent_manage',
                'arguments' => ['action' => 'list'],
                '_meta' => [ProtocolVersions::META_PROTOCOL_VERSION => ProtocolVersions::V2026_07_28],
            ],
        ]);

        $this->assertEquals(200, $this->pass($request)->getStatusCode());
        $this->assertTrue(ProtocolContext::current()->stateless);
    }

    // ── SEP-2567: sessions removed ───────────────────────────────────────────

    public function test_drops_inbound_session_id_for_2026_07_28_requests(): void
    {
        $request = $this->request(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => ['_meta' => [ProtocolVersions::META_PROTOCOL_VERSION => ProtocolVersions::V2026_07_28]],
            ],
            [ProtocolVersions::HEADER_SESSION_ID => 'stale-session-uuid'],
        );

        $this->pass($request);

        $this->assertNull($request->header(ProtocolVersions::HEADER_SESSION_ID));
    }

    public function test_keeps_session_id_for_legacy_clients(): void
    {
        $request = $this->request(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []],
            [
                ProtocolVersions::HEADER_PROTOCOL_VERSION => ProtocolVersions::V2025_11_25,
                ProtocolVersions::HEADER_SESSION_ID => 'legacy-session-uuid',
            ],
        );

        $this->pass($request);

        $this->assertSame('legacy-session-uuid', $request->header(ProtocolVersions::HEADER_SESSION_ID));
        $this->assertFalse(ProtocolContext::current()->stateless);
    }

    // ── Backward compatibility ───────────────────────────────────────────────

    public function test_bare_legacy_request_falls_back_to_the_transport_default(): void
    {
        $request = $this->request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);

        $this->assertEquals(200, $this->pass($request)->getStatusCode());
        $this->assertSame(ProtocolVersions::FALLBACK, ProtocolContext::current()->version);
        $this->assertFalse(ProtocolContext::current()->stateless);
    }

    public function test_initialize_handshake_still_negotiates_its_body_version(): void
    {
        $request = $this->request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => ProtocolVersions::V2025_11_25,
                'clientInfo' => ['name' => 'legacy-client'],
            ],
        ]);

        $this->assertEquals(200, $this->pass($request)->getStatusCode());
        $this->assertSame(ProtocolVersions::V2025_11_25, ProtocolContext::current()->version);
    }

    public function test_unsupported_initialize_version_is_left_to_the_initialize_handler(): void
    {
        // The handler answers with the supported list so the client can downgrade —
        // a 400 here would break that negotiation.
        $request = $this->request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '1999-01-01'],
        ]);

        $this->assertEquals(200, $this->pass($request)->getStatusCode());
    }

    // ── SEP-2243: routing headers must agree with the body ───────────────────

    public function test_rejects_protocol_version_header_that_contradicts_meta(): void
    {
        $request = $this->request(
            [
                'jsonrpc' => '2.0',
                'id' => 7,
                'method' => 'tools/list',
                'params' => ['_meta' => [ProtocolVersions::META_PROTOCOL_VERSION => ProtocolVersions::V2026_07_28]],
            ],
            [ProtocolVersions::HEADER_PROTOCOL_VERSION => ProtocolVersions::V2025_11_25],
        );

        $response = $this->pass($request);
        $body = json_decode($response->getContent(), true);

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertSame(-32600, $body['error']['code']);
        $this->assertSame(7, $body['id']);
        $this->assertStringContainsString('mismatch', $body['error']['message']);
    }

    public function test_rejects_unsupported_protocol_version_in_meta(): void
    {
        $request = $this->request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => [ProtocolVersions::META_PROTOCOL_VERSION => '1999-01-01']],
        ]);

        $response = $this->pass($request);

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertStringContainsString('Unsupported', json_decode($response->getContent(), true)['error']['message']);
    }

    public function test_rejects_mcp_method_header_that_contradicts_the_body(): void
    {
        $request = $this->request(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []],
            [ProtocolVersions::HEADER_METHOD => 'tools/call'],
        );

        $this->assertEquals(400, $this->pass($request)->getStatusCode());
    }

    public function test_rejects_mcp_name_header_that_contradicts_the_body(): void
    {
        $request = $this->request(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'agent_manage', 'arguments' => []],
            ],
            [ProtocolVersions::HEADER_NAME => 'budget_manage'],
        );

        $this->assertEquals(400, $this->pass($request)->getStatusCode());
    }

    public function test_rejects_mcp_name_header_on_a_method_with_no_target(): void
    {
        $request = $this->request(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []],
            [ProtocolVersions::HEADER_NAME => 'agent_manage'],
        );

        $this->assertEquals(400, $this->pass($request)->getStatusCode());
    }

    public function test_accepts_matching_routing_headers(): void
    {
        $request = $this->request(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'agent_manage', 'arguments' => ['action' => 'list']],
            ],
            [
                ProtocolVersions::HEADER_PROTOCOL_VERSION => ProtocolVersions::V2026_07_28,
                ProtocolVersions::HEADER_METHOD => 'tools/call',
                ProtocolVersions::HEADER_NAME => 'agent_manage',
            ],
        );

        $this->assertEquals(200, $this->pass($request)->getStatusCode());
        $this->assertSame(ProtocolVersions::V2026_07_28, ProtocolContext::current()->version);
    }

    public function test_matches_resource_uri_for_resources_read(): void
    {
        $request = $this->request(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => ['uri' => 'fleetq://approvals']],
            [ProtocolVersions::HEADER_NAME => 'fleetq://approvals'],
        );

        $this->assertEquals(200, $this->pass($request)->getStatusCode());
    }

    public function test_ignores_a_body_that_is_not_a_json_object(): void
    {
        $request = Request::create('/mcp', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '[]');

        $this->assertEquals(200, $this->pass($request)->getStatusCode());
        $this->assertSame(ProtocolVersions::FALLBACK, ProtocolContext::current()->version);
    }
}
