<?php

namespace App\Domain\GitRepository\Services;

use App\Domain\Approval\Actions\CreateActionProposalAction;
use App\Domain\GitRepository\Exceptions\GitOperationProposedException;
use App\Domain\GitRepository\Exceptions\GitOperationRefusedException;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Shared\Models\Team;

/**
 * Gates git client write methods through the team's per-tier risk policy
 * (Sprint 3c). Mirrors IntegrationActionGate but operates at the git
 * client layer so it covers all callers: MCP tools, REST API, indirect
 * agent execution.
 *
 * Risk classification is explicit-per-method (we own GitClientInterface,
 * unlike integration drivers where verb-name heuristics are used).
 *
 * Bypass via container binding 'git_gate.bypass' lets ActionProposalExecutor
 * re-run an approved proposal without recursion. Worktree/agent execution
 * code can also opt-in via the same binding when the surrounding context
 * already has user authorization.
 */
class GitOperationGate
{
    /**
     * @var array<string, string>
     */
    private const RISK_MAP = [
        // low — read
        'ping' => 'low',
        'readFile' => 'low',
        'listFiles' => 'low',
        'getFileTree' => 'low',
        'listPullRequests' => 'low',
        'getPullRequestStatus' => 'low',
        'getCommitLog' => 'low',
        // medium — branch / commit / push / single-file write
        'createBranch' => 'medium',
        'commit' => 'medium',
        'push' => 'medium',
        'writeFile' => 'medium',
        // high — PR lifecycle / workflow dispatch / release publishing
        'createPullRequest' => 'high',
        'mergePullRequest' => 'high',
        'closePullRequest' => 'high',
        'dispatchWorkflow' => 'high',
        'createRelease' => 'high',
    ];

    public function __construct(
        private readonly CreateActionProposalAction $createActionProposal,
    ) {}

    /**
     * Decide whether to allow, propose, or refuse this git method call.
     *
     * - 'auto' → returns silently; caller proceeds.
     * - 'ask'  → creates an ActionProposal and throws GitOperationProposedException.
     * - 'reject' → throws GitOperationRefusedException without creating a proposal.
     *
     * Bypass: when `app('git_gate.bypass')` is bound truthy, the gate is a no-op.
     *
     * @param  array<string, mixed>  $args
     */
    public function check(GitRepository $repo, string $method, array $args): void
    {
        if (app()->bound('git_gate.bypass') && app('git_gate.bypass')) {
            return;
        }

        $team = Team::find($repo->team_id);
        if (! $team) {
            return;
        }

        $policy = $this->resolvePolicy($team);
        $risk = self::classifyMethod($method);
        $decision = $policy[$risk] ?? 'auto';

        if ($decision === 'auto') {
            return;
        }

        if ($decision === 'reject') {
            throw new GitOperationRefusedException($method, $risk);
        }

        $proposal = $this->createActionProposal->execute(
            teamId: $repo->team_id,
            targetType: 'git_push',
            targetId: $repo->id,
            summary: ucfirst($risk)."-risk git operation: {$repo->getAttribute('provider')->value} :: {$method}",
            payload: [
                'repository_id' => $repo->id,
                'method' => $method,
                'args' => $args,
            ],
            userId: auth()->id(),
            riskLevel: $risk,
            expiresAt: now()->addHours(24),
        );

        throw new GitOperationProposedException(
            proposalId: $proposal->id,
            method: $method,
            riskLevel: $risk,
        );
    }

    /**
     * Explicit per-method risk. Unknown methods default to 'high' (safe
     * default — better to gate an unknown write than silently allow it).
     */
    public static function classifyMethod(string $method): string
    {
        return self::RISK_MAP[$method] ?? 'high';
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
