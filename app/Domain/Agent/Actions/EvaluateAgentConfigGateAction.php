<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Evaluation\Actions\ReplayEvaluationDatasetAction;
use Illuminate\Support\Facades\Log;

/**
 * Eval-gate on agent config change (eve "eval as deploy gate" borrow).
 *
 * Given a candidate config change, replays the agent's configured eval dataset
 * against the *candidate* provider/model/system-prompt and returns a verdict —
 * WITHOUT mutating the agent. Callers (e.g. agent_update) hold the change when
 * the verdict fails so a prompt/model regression stops before it reaches the
 * agent's live runs.
 *
 * Gating is opt-in: the gate only runs when agent.eval_gate.enabled is on AND
 * the agent has config.eval_gate_dataset_id set. Anything else is a passthrough
 * (gated=false, passed=true). The gate is fail-open on infra errors: a replay
 * failure logs and allows the change rather than locking out config edits.
 */
class EvaluateAgentConfigGateAction
{
    /** @var list<string> */
    private const DEFAULT_CRITERIA = ['correctness', 'relevance'];

    public function __construct(
        private readonly ReplayEvaluationDatasetAction $replay,
    ) {}

    /**
     * @param  array<string, mixed>  $candidate  The proposed update payload.
     * @return array{gated: bool, passed: bool, reason: string, score?: float, threshold?: float, run_id?: string}
     */
    public function execute(Agent $agent, array $candidate): array
    {
        if (! config('agent.eval_gate.enabled')) {
            return ['gated' => false, 'passed' => true, 'reason' => 'Eval gate disabled.'];
        }

        $datasetId = $this->resolveDatasetId($agent, $candidate);
        if ($datasetId === null) {
            return ['gated' => false, 'passed' => true, 'reason' => 'No eval dataset configured for this agent.'];
        }

        if ($agent->team_id === null) {
            return ['gated' => false, 'passed' => true, 'reason' => 'Agent has no team.'];
        }

        $provider = $candidate['provider'] ?? $agent->provider;
        $model = $candidate['model'] ?? $agent->model;
        if (! is_string($provider) || $provider === '' || ! is_string($model) || $model === '') {
            return ['gated' => false, 'passed' => true, 'reason' => 'Candidate provider/model not resolvable.'];
        }

        $threshold = (float) config('agent.eval_gate.threshold', ReplayEvaluationDatasetAction::REGRESSION_THRESHOLD);

        try {
            $run = $this->replay->execute(
                teamId: (string) $agent->team_id,
                datasetId: $datasetId,
                targetProvider: $provider,
                targetModel: $model,
                systemPrompt: $this->candidateSystemPrompt($agent, $candidate),
                criteria: $this->resolveCriteria($agent, $candidate),
            );
        } catch (\Throwable $e) {
            Log::warning('EvaluateAgentConfigGate: replay failed, allowing change (fail-open)', [
                'agent_id' => $agent->id,
                'dataset_id' => $datasetId,
                'error' => $e->getMessage(),
            ]);

            return ['gated' => false, 'passed' => true, 'reason' => 'Eval gate could not run: '.$e->getMessage()];
        }

        // Read via getAttribute (typed mixed) — the magic `summary` accessor is
        // inferred as string|null by larastan despite the runtime array cast.
        $summary = $run->getAttribute('summary');
        $summary = is_array($summary) ? $summary : [];
        $score = (float) ($summary['overall_avg_score'] ?? 0.0);
        $passed = $score >= $threshold;

        return [
            'gated' => true,
            'passed' => $passed,
            'reason' => $passed
                ? "Candidate scored {$score} ≥ threshold {$threshold}."
                : "Candidate scored {$score} < threshold {$threshold} — change held.",
            'score' => $score,
            'threshold' => $threshold,
            'run_id' => (string) $run->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function resolveDatasetId(Agent $agent, array $candidate): ?string
    {
        $candidateConfig = $candidate['config'] ?? null;
        $fromCandidate = is_array($candidateConfig) ? ($candidateConfig['eval_gate_dataset_id'] ?? null) : null;
        $agentConfig = $agent->config ?? [];
        $id = $fromCandidate ?? ($agentConfig['eval_gate_dataset_id'] ?? null);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<string>
     */
    private function resolveCriteria(Agent $agent, array $candidate): array
    {
        $criteria = $candidate['evaluation_criteria'] ?? $agent->evaluation_criteria ?? null;

        if (! is_array($criteria) || $criteria === []) {
            return self::DEFAULT_CRITERIA;
        }

        return array_values(array_filter($criteria, fn ($c) => is_string($c) && $c !== ''));
    }

    /**
     * Assemble the candidate system prompt from the proposed identity fields,
     * falling back to the agent's current values, so the replay tests the new
     * prompt rather than the saved one.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function candidateSystemPrompt(Agent $agent, array $candidate): ?string
    {
        $parts = array_filter([
            $candidate['role'] ?? $agent->role,
            $candidate['goal'] ?? $agent->goal,
            $candidate['backstory'] ?? $agent->backstory,
        ], fn ($v) => is_string($v) && $v !== '');

        return $parts === [] ? null : implode("\n\n", $parts);
    }
}
