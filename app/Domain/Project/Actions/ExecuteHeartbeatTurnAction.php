<?php

namespace App\Domain\Project\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Metrics\Models\Metric;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Signal\Models\Signal;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;

class ExecuteHeartbeatTurnAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly TriggerProjectRunAction $triggerRun,
    ) {}

    public function execute(Project $project): ?ProjectRun
    {
        $schedule = $project->schedule;
        if (! $schedule || ! $schedule->heartbeat_enabled) {
            return null;
        }

        $context = $this->gatherContext($project, $schedule->heartbeat_context_sources ?? []);

        $agentId = $project->agent_config['lead_agent_id'] ?? null;
        $agent = $agentId ? Agent::withoutGlobalScopes()->where('team_id', $project->team_id)->find($agentId) : null;

        $prompt = $this->buildHeartbeatPrompt($context);

        $request = new AiRequestDTO(
            provider: $agent->provider ?? 'anthropic',
            model: $agent->model ?? 'claude-haiku-4-5',
            systemPrompt: $agent->backstory ?? '',
            userPrompt: $prompt,
            teamId: $project->team_id,
            maxTokens: 2048,
        );

        $response = $this->gateway->complete($request);
        $text = $response->content;

        if ($this->isSilentResponse($text)) {
            return null;
        }

        return $this->triggerRun->execute($project, 'heartbeat', [
            'heartbeat_findings' => $text,
            'context_sources' => $schedule->heartbeat_context_sources,
        ]);
    }

    private function gatherContext(Project $project, array $sources): array
    {
        $context = [];
        $teamId = $project->team_id;

        if (in_array('signals', $sources)) {
            $context['recent_signals'] = Signal::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->latest()
                ->take(10)
                ->get(['id', 'source', 'payload', 'created_at'])
                ->toArray();
        }

        if (in_array('metrics', $sources)) {
            $context['recent_metrics'] = Metric::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('created_at', '>=', now()->subHours(24))
                ->latest()
                ->take(20)
                ->get(['name', 'value', 'tags', 'created_at'])
                ->toArray();
        }

        if (in_array('audit', $sources)) {
            $context['recent_audit'] = AuditEntry::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('created_at', '>=', now()->subHours(4))
                ->latest()
                ->take(15)
                ->get(['event', 'description', 'created_at'])
                ->toArray();
        }

        if (in_array('experiments', $sources)) {
            $terminalStatuses = array_map(
                fn (ExperimentStatus $s) => $s->value,
                ExperimentStatus::terminalStates(),
            );

            $context['active_experiments'] = Experiment::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->whereNotIn('status', $terminalStatuses)
                ->get(['id', 'title', 'status', 'updated_at'])
                ->toArray();
        }

        return $context;
    }

    private function buildHeartbeatPrompt(array $context): string
    {
        $contextJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
        ## Heartbeat Check

        You are performing a scheduled heartbeat check for project monitoring.
        Review the current state below and decide if any action is needed.

        ### Current Context
        {$contextJson}

        ### Instructions
        - If you find something that needs attention (anomaly, failure, opportunity, important signal) — describe what you found and what action should be taken.
        - If everything looks normal — respond with exactly: ALL_CLEAR
        - Be concise. Focus only on actionable findings.
        PROMPT;
    }

    private function isSilentResponse(string $text): bool
    {
        $normalized = strtoupper(trim($text));

        return str_contains($normalized, 'ALL_CLEAR')
            || str_contains($normalized, 'ALL CLEAR')
            || str_contains($normalized, 'NOTHING TO REPORT');
    }
}
