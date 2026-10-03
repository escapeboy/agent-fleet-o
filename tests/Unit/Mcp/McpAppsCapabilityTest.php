<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Protocol\ProtocolContext;
use App\Mcp\Services\McpAppsCapability;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Pins the MCP Apps capability negotiation to SEP-1865 (Stable 2026-01-26).
 *
 * This gate is load-bearing and silent-failing: if the extension id, the MIME
 * type, or the `capabilities.extensions[<id>].mimeTypes` shape drifts from the
 * spec, every `ui://fleetq/*` widget stops rendering in every host with no
 * error. These assertions fail loudly if the wire contract changes.
 */
class McpAppsCapabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** @return array<string, mixed> */
    private function specCapabilities(): array
    {
        return [
            'extensions' => [
                'io.modelcontextprotocol/ui' => [
                    'mimeTypes' => ['text/html;profile=mcp-app'],
                ],
            ],
        ];
    }

    public function test_constants_match_the_spec(): void
    {
        // SEP-1865 §Extension Identifier + §UI Resources.
        $this->assertSame('io.modelcontextprotocol/ui', McpAppsCapability::EXTENSION_ID);
        $this->assertSame('text/html;profile=mcp-app', McpAppsCapability::MIME_TYPE);
    }

    public function test_detects_capable_client_with_spec_exact_payload(): void
    {
        McpAppsCapability::store('sess-1', $this->specCapabilities());

        $this->assertTrue(McpAppsCapability::for('sess-1'));
    }

    public function test_rejects_client_without_the_ui_extension(): void
    {
        McpAppsCapability::store('sess-2', ['extensions' => ['some.other/ext' => []]]);

        $this->assertFalse(McpAppsCapability::for('sess-2'));
    }

    public function test_rejects_client_whose_mimetypes_lack_the_app_mime(): void
    {
        McpAppsCapability::store('sess-3', [
            'extensions' => [
                'io.modelcontextprotocol/ui' => ['mimeTypes' => ['text/plain']],
            ],
        ]);

        $this->assertFalse(McpAppsCapability::for('sess-3'));
    }

    public function test_rejects_null_capabilities(): void
    {
        McpAppsCapability::store('sess-4', null);

        $this->assertFalse(McpAppsCapability::for('sess-4'));
    }

    public function test_for_returns_false_for_null_session(): void
    {
        $this->assertFalse(McpAppsCapability::for(null));
    }

    public function test_unknown_session_defaults_to_unsupported(): void
    {
        $this->assertFalse(McpAppsCapability::for('never-stored'));
    }

    public function test_stateless_request_uses_capabilities_from_meta(): void
    {
        app()->instance(ProtocolContext::BINDING, new ProtocolContext(
            version: '2026-07-28', stateless: true, clientCapabilities: $this->specCapabilities(),
        ));
        $this->assertTrue(McpAppsCapability::active());

        app()->instance(ProtocolContext::BINDING, new ProtocolContext(
            version: '2026-07-28', stateless: true, clientCapabilities: [],
        ));
        $this->assertFalse(McpAppsCapability::active());
    }

    public function test_legacy_http_request_uses_the_issued_session_id(): void
    {
        McpAppsCapability::recordInitialize($this->specCapabilities());
        $sessionId = app(McpAppsCapability::ISSUED_SESSION_BINDING);

        app()->instance(ProtocolContext::BINDING, new ProtocolContext(version: '2025-11-25', stateless: false));
        request()->headers->set('Mcp-Session-Id', $sessionId);
        $this->assertTrue(McpAppsCapability::active());

        request()->headers->set('Mcp-Session-Id', 'unknown-session');
        $this->assertFalse(McpAppsCapability::active());
    }

    public function test_stdio_uses_the_process_flag_from_initialize(): void
    {
        app()->forgetInstance(ProtocolContext::BINDING);

        McpAppsCapability::recordInitialize($this->specCapabilities());
        $this->assertTrue(McpAppsCapability::active());

        McpAppsCapability::recordInitialize(null);
        $this->assertFalse(McpAppsCapability::active());
    }
}
