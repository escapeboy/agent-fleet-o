<?php

namespace App\Domain\Tool\Services;

use App\Domain\Approval\Actions\CreateActionProposalAction;
use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Tool\Models\Tool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Single write path for MCP tool definitions fetched from a server.
 *
 * Every automated refresh (health check, hourly refresh, remote probe) goes
 * through sync(). Definitions are always stored in FleetQ's shape
 * ({name, description, input_schema[, annotations]}); the raw MCP tools/list
 * shape uses inputSchema, which ToolTranslator does not read.
 *
 * With tools.definition_pinning.enabled, a change against the stored
 * (approved) set is held in pending_tool_definitions and an ActionProposal
 * carries the diff. Cloud-provider agents keep the approved set; local agents,
 * which read tools/list from the server themselves, do not get the server while
 * a change is pending (ClaudeCodeMcpConfigBuilder).
 * The first definitions a tool ever gets are trusted (trust on first use);
 * edits made by a person through the UI/API write directly and count as the
 * approval.
 */
class ToolDefinitionPinner
{
    public const TARGET_TYPE = 'mcp_tool_definitions';

    public const APPLIED = 'applied';

    public const UNCHANGED = 'unchanged';

    public const PENDING = 'pending';

    public const ALREADY_PENDING = 'already_pending';

    public function __construct(private readonly CreateActionProposalAction $createProposal) {}

    /**
     * @param  array<int, mixed>  $fetched  tools/list entries, raw or normalised
     */
    public function sync(Tool $tool, array $fetched): string
    {
        $incoming = $this->normalize($fetched);
        $current = $this->normalize($tool->tool_definitions ?? []);

        // An empty list is what McpHttpClient returns for a JSON-RPC error body;
        // never let it wipe definitions that agents rely on.
        if ($incoming === [] && $current !== []) {
            Log::warning('ToolDefinitionPinner: server returned no tools; stored definitions kept', ['tool_id' => $tool->id]);

            return self::UNCHANGED;
        }

        $incomingHash = $this->hash($incoming);

        if ($incomingHash === $this->hash($current)) {
            $updates = [];
            // Same content in the raw shape: store it in the shape the translator reads.
            // Checked by key, not by array equality: PostgreSQL JSONB reorders keys.
            if ($this->hasRawShape($tool->tool_definitions ?? [])) {
                $updates['tool_definitions'] = $incoming;
            }
            // The server went back to the approved set; the pending change is moot.
            if ($tool->pending_definitions_hash !== null) {
                $updates += $this->clearedPending();
            }
            if ($updates !== []) {
                $tool->update($updates);
            }

            return self::UNCHANGED;
        }

        if (! (bool) config('tools.definition_pinning.enabled', false) || $current === []) {
            $tool->update(['tool_definitions' => $incoming] + $this->clearedPending());

            return self::APPLIED;
        }

        return DB::transaction(function () use ($tool, $current, $incoming, $incomingHash): string {
            $locked = Tool::withoutGlobalScopes()->lockForUpdate()->findOrFail($tool->id);

            if ($locked->pending_definitions_hash === $incomingHash && $this->hasUnexpiredProposal($locked, $incomingHash)) {
                return self::ALREADY_PENDING;
            }

            $diff = $this->diff($current, $incoming);

            $locked->update([
                'pending_tool_definitions' => $incoming,
                'pending_definitions_hash' => $incomingHash,
                'pending_definitions_detected_at' => now(),
            ]);

            $this->createProposal->execute(
                teamId: (string) $locked->team_id,
                targetType: self::TARGET_TYPE,
                targetId: (string) $locked->id,
                summary: sprintf(
                    'MCP server "%s" changed its tool definitions: %d added, %d removed, %d changed',
                    $locked->name,
                    count($diff['added']),
                    count($diff['removed']),
                    count($diff['changed']),
                ),
                payload: [
                    'tool_id' => (string) $locked->id,
                    'tool_name' => $locked->name,
                    'pending_hash' => $incomingHash,
                    'diff' => $diff,
                    // The reviewer needs the new wording to spot a poisoned description.
                    'new_descriptions' => $this->descriptionsFor($incoming, [...$diff['added'], ...array_column($diff['changed'], 'name')]),
                ],
                riskLevel: 'high',
                expiresAt: now()->addDays(max(1, (int) config('tools.definition_pinning.proposal_ttl_days', 7))),
            );

            Log::warning('MCP tool definitions changed; held for approval', [
                'tool_id' => $locked->id,
                'team_id' => $locked->team_id,
                'added' => $diff['added'],
                'removed' => $diff['removed'],
                'changed' => array_column($diff['changed'], 'name'),
            ]);

            $tool->setRawAttributes($locked->getAttributes(), true);

            return self::PENDING;
        });
    }

    /**
     * Apply the pending definitions an approved proposal refers to.
     *
     * @throws RuntimeException when the pending set changed since the proposal was made
     */
    public function approvePending(Tool $tool, string $hash): void
    {
        DB::transaction(function () use ($tool, $hash): void {
            $locked = Tool::withoutGlobalScopes()->lockForUpdate()->findOrFail($tool->id);

            if ($locked->pending_definitions_hash !== $hash || $locked->pending_tool_definitions === null) {
                throw new RuntimeException('The pending tool definitions changed since this approval was requested; review the newer request instead.');
            }

            $locked->update(['tool_definitions' => $locked->pending_tool_definitions] + $this->clearedPending());
        });
    }

    /**
     * @param  array<int, mixed>  $definitions
     * @return list<array<string, mixed>>
     */
    public function normalize(array $definitions): array
    {
        $normalized = [];
        $seen = [];

        foreach ($definitions as $definition) {
            if (! is_array($definition) || ! is_string($definition['name'] ?? null) || $definition['name'] === '') {
                continue;
            }

            // One definition per name. hash(), diff() and the stored list must all
            // describe the same set, or a duplicate could slip past the approval.
            if (isset($seen[$definition['name']])) {
                Log::warning('ToolDefinitionPinner: duplicate tool name dropped', ['name' => $definition['name']]);

                continue;
            }
            $seen[$definition['name']] = true;

            $schema = $definition['input_schema'] ?? $definition['inputSchema'] ?? null;

            $entry = [
                'name' => $definition['name'],
                'description' => (string) ($definition['description'] ?? ''),
                'input_schema' => is_array($schema) ? $schema : ['type' => 'object', 'properties' => []],
            ];

            if (is_array($definition['annotations'] ?? null) && $definition['annotations'] !== []) {
                $entry['annotations'] = $definition['annotations'];
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * @param  array<int, mixed>  $definitions  normalised definitions
     */
    public function hash(array $definitions): string
    {
        $byName = [];
        foreach ($definitions as $definition) {
            $byName[$definition['name']] = $this->sortRecursive($definition);
        }
        ksort($byName);

        return hash('sha256', $this->canonicalJson(array_values($byName)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $old
     * @param  array<int, array<string, mixed>>  $new
     * @return array{added: list<string>, removed: list<string>, changed: list<array{name: string, fields: list<string>}>}
     */
    public function diff(array $old, array $new): array
    {
        $oldByName = array_column($old, null, 'name');
        $newByName = array_column($new, null, 'name');

        $changed = [];
        foreach (array_intersect_key($newByName, $oldByName) as $name => $definition) {
            $fields = [];
            foreach (['description', 'input_schema', 'annotations'] as $field) {
                if ($this->canonicalJson($definition[$field] ?? null) !== $this->canonicalJson($oldByName[$name][$field] ?? null)) {
                    $fields[] = $field;
                }
            }
            if ($fields !== []) {
                $changed[] = ['name' => (string) $name, 'fields' => $fields];
            }
        }

        return [
            'added' => array_map('strval', array_keys(array_diff_key($newByName, $oldByName))),
            'removed' => array_map('strval', array_keys(array_diff_key($oldByName, $newByName))),
            'changed' => $changed,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $definitions
     * @param  list<string>  $names
     * @return array<string, string>
     */
    private function descriptionsFor(array $definitions, array $names): array
    {
        $descriptions = [];
        foreach ($definitions as $definition) {
            if (in_array($definition['name'], $names, true)) {
                $descriptions[$definition['name']] = ToolErrorGuard::capMessage((string) $definition['description'], 2000);
            }
        }

        return $descriptions;
    }

    private function hasUnexpiredProposal(Tool $tool, string $hash): bool
    {
        return ActionProposal::withoutGlobalScopes()
            ->where('team_id', $tool->team_id)
            ->where('target_type', self::TARGET_TYPE)
            ->where('target_id', $tool->id)
            ->where('payload->pending_hash', $hash)
            // Any decided-or-deciding proposal for this exact change; only an expired
            // one (nobody decided) is proposed again.
            ->where('status', '!=', ActionProposalStatus::Expired->value)
            ->exists();
    }

    /**
     * @param  array<int, mixed>  $stored
     */
    private function hasRawShape(array $stored): bool
    {
        foreach ($stored as $definition) {
            if (is_array($definition) && (array_key_exists('inputSchema', $definition) || ! array_key_exists('input_schema', $definition))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{pending_tool_definitions: null, pending_definitions_hash: null, pending_definitions_detected_at: null}
     */
    private function clearedPending(): array
    {
        return [
            'pending_tool_definitions' => null,
            'pending_definitions_hash' => null,
            'pending_definitions_detected_at' => null,
        ];
    }

    /**
     * Throws on unencodable input (e.g. invalid UTF-8) instead of collapsing it to
     * an empty string, which would make different definitions hash alike.
     */
    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->sortRecursive($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->sortRecursive($item), $value);
    }
}
