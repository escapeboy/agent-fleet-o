<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Models\ActionProposal;
use App\Infrastructure\AI\Gateways\ClaudeCodeSettingsBuilder;
use App\Infrastructure\AI\Gateways\LocalAgentOutputParser;

/**
 * Turns the tool calls Claude Code denied in dontAsk mode into approval
 * proposals: one pending proposal per distinct grantable rule. Approving one
 * stores a lasting allow grant for the agent (ActionProposalExecutor).
 *
 * The payload holds only the rule and tool name, never the raw command or
 * arguments, so no secret from a denied call reaches the inbox or chat links.
 */
class CreatePermissionDenialProposalsAction
{
    public const TARGET_TYPE = 'claude_code_permission';

    public const MAX_PROPOSALS_PER_RUN = 10;

    private const FILE_TOOLS = [
        'Edit' => 'file_path',
        'Write' => 'file_path',
        'MultiEdit' => 'file_path',
        'NotebookEdit' => 'notebook_path',
    ];

    public function __construct(private readonly CreateActionProposalAction $createProposal) {}

    /**
     * @param  array<int, array{tool_name: string, tool_input: array<mixed>}>  $denials
     * @return int number of proposals created
     */
    public function execute(string $teamId, string $agentId, array $denials, ?string $experimentId = null): int
    {
        $agent = Agent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($agentId);

        if (! $agent instanceof Agent) {
            return 0;
        }

        $config = is_array($agent->config) ? $agent->config : [];
        $shellOff = ($config['allow_shell'] ?? true) === false;
        $grants = $config[ClaudeCodeSettingsBuilder::GRANTS_CONFIG_KEY]['allow'] ?? [];
        $granted = is_array($grants) ? $grants : [];

        $rules = [];
        foreach ($denials as $denial) {
            $toolName = $denial['tool_name'];
            $rule = $this->ruleForDenial($toolName, $denial['tool_input']);
            if ($rule === null || isset($rules[$rule])) {
                continue;
            }
            if ($shellOff && str_starts_with($rule, 'Bash')) {
                continue;
            }
            if (! ClaudeCodeSettingsBuilder::isGrantableAllowRule($rule) || in_array($rule, $granted, true)) {
                continue;
            }
            $rules[$rule] = $toolName;
        }

        if ($rules === []) {
            return 0;
        }

        $pendingRules = ActionProposal::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('target_type', self::TARGET_TYPE)
            ->where('target_id', $agent->id)
            ->where('status', ActionProposalStatus::Pending->value)
            ->get()
            ->map(fn (ActionProposal $p) => $p->payload['rule'] ?? null)
            ->filter(fn ($rule) => is_string($rule))
            ->all();

        $created = 0;
        foreach ($rules as $rule => $toolName) {
            if ($created >= self::MAX_PROPOSALS_PER_RUN) {
                break;
            }
            if (in_array($rule, $pendingRules, true)) {
                continue;
            }

            $this->createProposal->execute(
                teamId: $teamId,
                targetType: self::TARGET_TYPE,
                targetId: $agent->id,
                summary: "Agent \"{$agent->name}\" was blocked from using {$rule}. Approve to allow it for this agent.",
                payload: [
                    'agent_id' => $agent->id,
                    'rule' => $rule,
                    'tool_name' => $toolName,
                    'experiment_id' => $experimentId,
                ],
                agentId: $agent->id,
                riskLevel: 'high',
            );
            $created++;
        }

        return $created;
    }

    /**
     * The permission rule that would have allowed a denied call, or null when
     * the call must not be offered as a grant.
     *
     * @param  array<mixed>  $input
     */
    public function ruleForDenial(string $toolName, array $input): ?string
    {
        if ($toolName === 'Bash') {
            $command = $input['command'] ?? null;
            if (! is_string($command) || preg_match('/(^|[\s;&|(])sudo(\s|$)/', $command) === 1) {
                return null;
            }
            $program = LocalAgentOutputParser::bashProgram($command);

            return $program === '?' ? null : "Bash({$program}:*)";
        }

        if (isset(self::FILE_TOOLS[$toolName])) {
            $path = $input[self::FILE_TOOLS[$toolName]] ?? null;
            if (! is_string($path) || ! str_starts_with($path, '/') || str_contains($path, '..')) {
                return null;
            }

            return $toolName.'(//'.ltrim($path, '/').')';
        }

        if (str_starts_with($toolName, 'mcp__')) {
            return null;
        }

        return preg_match('/^[A-Za-z]+$/', $toolName) === 1 ? $toolName : null;
    }
}
