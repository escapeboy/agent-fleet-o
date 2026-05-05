<?php

namespace App\Domain\Integration\Services;

use App\Domain\Approval\Actions\CreateActionProposalAction;
use App\Domain\Integration\Exceptions\IntegrationActionProposedException;
use App\Domain\Integration\Exceptions\IntegrationActionRefusedException;
use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Models\Team;

/**
 * Gates integration action execution through the team's per-tier risk
 * policy (Sprint 3c). Mirrors the assistant slow-mode gate's policy
 * resolution but operates at the integration runtime layer so it covers
 * all callers: REST API, MCP tools, and indirect calls via assistant.
 *
 * v1 risk classification is heuristic-by-action-name; per-driver
 * riskLevel() override is deferred. Workflow-context bypass is not
 * implemented — workflows inherit team policy, so set the policy to
 * 'auto' for relevant tiers if you want unimpeded automation.
 */
class IntegrationActionGate
{
    public function __construct(
        private readonly CreateActionProposalAction $createActionProposal,
    ) {}

    /**
     * Decide whether to allow, propose, or refuse this action.
     *
     * - 'auto' → returns silently; caller proceeds.
     * - 'ask'  → creates an ActionProposal and throws IntegrationActionProposedException.
     * - 'reject' → throws IntegrationActionRefusedException without creating a proposal.
     *
     * Bypass: when `app('integration_gate.bypass')` is bound truthy (set
     * by ActionProposalExecutor during approved-proposal re-execution),
     * the gate is a no-op so re-running an already-approved proposal
     * doesn't loop.
     *
     * @param  array<string, mixed>  $params
     */
    public function check(Integration $integration, string $action, array $params): void
    {
        if (app()->bound('integration_gate.bypass') && app('integration_gate.bypass')) {
            return;
        }

        $team = Team::find($integration->team_id);
        if (! $team) {
            return;
        }

        $policy = $this->resolvePolicy($team);
        $risk = self::classifyAction($action);
        $decision = $policy[$risk] ?? 'auto';

        if ($decision === 'auto') {
            return;
        }

        if ($decision === 'reject') {
            throw new IntegrationActionRefusedException($action, $risk);
        }

        // 'ask' → create proposal and throw
        $proposal = $this->createActionProposal->execute(
            teamId: $integration->team_id,
            targetType: 'integration_action',
            targetId: $integration->id,
            summary: ucfirst($risk)."-risk integration action: {$integration->getAttribute('driver')} :: {$action}",
            payload: [
                'integration_id' => $integration->id,
                'action' => $action,
                'params' => $params,
            ],
            userId: auth()->id(),
            riskLevel: $risk,
            expiresAt: now()->addHours(24),
        );

        throw new IntegrationActionProposedException(
            proposalId: $proposal->id,
            action: $action,
            riskLevel: $risk,
        );
    }

    /**
     * Heuristic risk classifier. Falls back to 'low' for unknown verbs.
     *
     * **High** — destructive: delete/remove/drop/destroy/cancel/terminate/uninstall/revoke/wipe/purge/archive plus force-merge, force-push semantics.
     * **Medium** — write: create/update/send/post/commit/push/merge/deploy/upsert/patch/edit/add/upload/import/sync/install.
     * **Low** — everything else (typically read: list/get/fetch/search/query/check/poll/ping/preview/validate).
     */
    public static function classifyAction(string $action): string
    {
        $a = strtolower($action);

        $high = [
            'delete', 'remove', 'drop', 'destroy', 'cancel', 'terminate',
            'uninstall', 'revoke', 'wipe', 'purge', 'archive',
            'force_merge', 'force_push', 'rollback',
        ];
        foreach ($high as $verb) {
            if (str_starts_with($a, $verb.'_') || str_ends_with($a, '_'.$verb) || $a === $verb) {
                return 'high';
            }
        }

        $medium = [
            'create', 'update', 'send', 'post', 'commit', 'push', 'merge',
            'deploy', 'upsert', 'patch', 'edit', 'add', 'upload', 'import',
            'sync', 'install', 'invite', 'assign', 'reassign', 'move', 'transfer',
        ];
        foreach ($medium as $verb) {
            if (str_starts_with($a, $verb.'_') || str_ends_with($a, '_'.$verb) || $a === $verb) {
                return 'medium';
            }
        }

        return 'low';
    }

    /**
     * @return array{low: string, medium: string, high: string}
     */
    private function resolvePolicy(Team $team): array
    {
        $defaults = ['low' => 'auto', 'medium' => 'auto', 'high' => 'auto'];
        $explicit = $team->settings['action_proposal_policy'] ?? null;

        if (is_array($explicit)) {
            $allowed = ['auto', 'ask', 'reject'];

            return [
                'low' => in_array($explicit['low'] ?? 'auto', $allowed, true) ? $explicit['low'] : 'auto',
                'medium' => in_array($explicit['medium'] ?? 'auto', $allowed, true) ? $explicit['medium'] : 'auto',
                'high' => in_array($explicit['high'] ?? 'auto', $allowed, true) ? $explicit['high'] : 'auto',
            ];
        }

        if ((bool) ($team->settings['slow_mode_enabled'] ?? false)) {
            return ['low' => 'auto', 'medium' => 'auto', 'high' => 'ask'];
        }

        return $defaults;
    }
}
