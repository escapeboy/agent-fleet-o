<?php

namespace App\Domain\Shared\Contracts;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Connectors / drivers / sources can opt into automatic MCP exposure by
 * implementing this contract. The platform's ConnectorMcpRegistrar discovers
 * implementers at boot and synthesizes a Tool subclass per connector so the
 * MCP server lists it without a hand-written tool file.
 *
 * Lives in Domain layer (not Mcp) so domain connectors can implement it
 * without violating the no-domain-imports-presentation architectural rule.
 *
 * Activepieces-inspired (build #3, Trendshift top-5 sprint).
 */
interface AutoRegistersAsMcpTool
{
    public function mcpName(): string;

    public function mcpDescription(): string;

    /**
     * @return array<string, mixed>
     */
    public function mcpInputSchema(JsonSchema $schema): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function mcpInvoke(array $params, string $teamId): array;

    /**
     * @return array{read_only?: bool, idempotent?: bool, assistant_tool?: 'read'|'write'|'destructive'|null}
     */
    public function mcpAnnotations(): array;
}
