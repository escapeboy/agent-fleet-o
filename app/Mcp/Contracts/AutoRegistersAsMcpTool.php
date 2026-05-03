<?php

namespace App\Mcp\Contracts;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Connectors / drivers / sources can opt into automatic MCP exposure by
 * implementing this contract. The platform's ConnectorMcpRegistrar discovers
 * implementers at boot and synthesizes a Tool subclass per connector so the
 * MCP server lists it without a hand-written tool file.
 *
 * Activepieces-inspired (build #3, Trendshift top-5 sprint).
 */
interface AutoRegistersAsMcpTool
{
    /**
     * Fully-qualified MCP tool name (snake_case, dotted is OK).
     * Must be unique across the server.
     *
     * Convention:
     *   - signal connectors → "signal.<driver>.<verb>"   e.g. signal.rss.poll
     *   - outbound          → "outbound.<channel>.<verb>"
     *   - integration       → "integration.<driver>.<verb>"
     */
    public function mcpName(): string;

    /** Human-readable description shown in tool listings. */
    public function mcpDescription(): string;

    /**
     * JSON schema for tool input. Same shape as Tool::schema().
     *
     * @return array<string, mixed>
     */
    public function mcpInputSchema(JsonSchema $schema): array;

    /**
     * Invoke the connector. Return value is JSON-encoded into the MCP Response.
     *
     * @param  array<string, mixed>  $params  Validated input
     * @param  string                $teamId  Resolved by SyntheticConnectorTool from app('mcp.team_id')
     * @return array<string, mixed>
     */
    public function mcpInvoke(array $params, string $teamId): array;

    /**
     * Annotation hints used by SyntheticConnectorTool when bridging to Laravel\Mcp.
     *
     * Defaults: {read_only: false, idempotent: false, assistant_tool: 'write'}.
     *
     * @return array{read_only?: bool, idempotent?: bool, assistant_tool?: 'read'|'write'|'destructive'|null}
     */
    public function mcpAnnotations(): array;
}
