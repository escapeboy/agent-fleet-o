<?php

namespace App\Domain\Assistant\Artifacts;

/**
 * Abstract base for every UI artifact type the assistant can emit.
 *
 * Hard invariant: NO instance of any concrete subclass may exist without
 * having passed through its own fromLlmArray() factory first. The constructor
 * is public by necessity (PHP doesn't let subclasses invoke private parents)
 * but callers outside of fromLlmArray() should never instantiate directly.
 *
 * Every subclass must implement:
 *  - TYPE constant naming the artifact type (matches DB enum + Blade component)
 *  - fromLlmArray(array $raw, array $toolCallsInTurn): ?static
 *  - toPayload(): array    — JSON-serializable representation for storage
 *
 * Provenance: subclasses that require a source_tool (data_table, chart)
 * MUST reject any payload whose source_tool did not appear in $toolCallsInTurn.
 */
abstract class BaseArtifact
{
    public const MAX_PAYLOAD_BYTES = 32_000;

    /**
     * Factory: build a validated VO from untrusted LLM output.
     * Returns null on any validation failure (silent drop).
     *
     * @param  array<string, mixed>  $raw
     * @param  list<array<string, mixed>>  $toolCallsInTurn
     */
    abstract public static function fromLlmArray(array $raw, array $toolCallsInTurn): ?static;

    /**
     * JSON-serializable representation to store on the assistant_messages row
     * and in the queryable assistant_ui_artifacts table.
     *
     * @return array<string, mixed>
     */
    abstract public function toPayload(): array;

    /**
     * The artifact type string. Matches the `type` column + Blade component name.
     */
    abstract public function type(): string;

    /**
     * Optional MCP tool name that produced the data displayed in this artifact.
     * null for types that never bind to a tool call (e.g. metric_card with literal).
     */
    public function sourceTool(): ?string
    {
        return null;
    }

    /**
     * Compute storage size of the payload for cap enforcement at save time.
     */
    public function sizeBytes(): int
    {
        return strlen(json_encode($this->toPayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * Helper for subclasses: verify a claimed source tool actually ran in this turn.
     *
     * @param  list<array<string, mixed>>  $toolCallsInTurn
     */
    final protected static function toolRanInTurn(string $toolName, array $toolCallsInTurn): bool
    {
        foreach ($toolCallsInTurn as $call) {
            $callName = $call['name'] ?? $call['tool'] ?? null;
            if ($callName === $toolName) {
                return true;
            }
        }

        return false;
    }
}
