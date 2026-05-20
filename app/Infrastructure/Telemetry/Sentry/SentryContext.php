<?php

declare(strict_types=1);

namespace App\Infrastructure\Telemetry\Sentry;

use Sentry\State\Scope;

/**
 * Pushes FleetQ sub-program context into the active Sentry scope.
 *
 * Always called inside a Sentry::withScope() callback so changes are restored
 * automatically — the caller (job middleware, web middleware, action) owns the
 * lifecycle.
 *
 * Tags exposed:
 *   - team_id, user_id (multi-tenant identity)
 *   - sub_program (e.g. 'experiment.stage.building', 'crew.task', 'workflow.node',
 *                  'outbound.email', 'llm.call', 'assistant.message', 'project.run')
 *   - experiment_id, experiment_stage, agent_id, crew_execution_id,
 *     workflow_id, workflow_node_id, project_run_id, signal_id, integration_id
 *
 * Empty / null values are dropped — Sentry would otherwise stringify them as 'null'
 * which pollutes tag-based search.
 */
final class SentryContext
{
    /**
     * Whitelist of context keys that become Sentry tags. Anything outside the
     * whitelist becomes a `setContext()` payload (richer object, not searchable).
     */
    private const TAG_KEYS = [
        'team_id',
        'user_id',
        'sub_program',
        'experiment_id',
        'experiment_stage',
        'agent_id',
        'crew_execution_id',
        'crew_task_id',
        'workflow_id',
        'workflow_node_id',
        'project_run_id',
        'signal_id',
        'integration_id',
        'tool_id',
        'skill_id',
        'outbound_action_id',
        'job',
        'queue',
        'provider',
        'model',
    ];

    /**
     * Apply context to the supplied scope. Does NOT call configureScope() —
     * the caller owns the scope lifecycle. This keeps tests deterministic
     * (no global mutation).
     *
     * @param  array<string, mixed>  $context
     */
    public function apply(Scope $scope, array $context): void
    {
        foreach (self::TAG_KEYS as $key) {
            $value = $context[$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $scope->setTag($key, (string) $value);
        }

        // Non-tag context (richer payloads). Sentry supports up to ~16KB.
        $extra = array_diff_key($context, array_flip(self::TAG_KEYS));
        if ($extra !== []) {
            $scope->setContext('fleetq', $extra);
        }
    }

    /**
     * Convenience: returns the whitelisted-key subset of a context array.
     * Useful when persisting the same tags into error_metadata rows.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    public function tagsFor(array $context): array
    {
        $tags = [];
        foreach (self::TAG_KEYS as $key) {
            $value = $context[$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $tags[$key] = (string) $value;
        }

        return $tags;
    }
}
