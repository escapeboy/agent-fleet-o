<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Budget\Actions\ReserveBudgetAction;
use App\Domain\Budget\Actions\SettleBudgetAction;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Decision\DTOs\Answer;
use App\Domain\Decision\Services\DecisionDriverResolver;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use Throwable;

/**
 * Runs a Decision skill: a saved, versionable set of typed questions put to a
 * decision model. Short-circuits before executeByType() because it never
 * touches the LLM gateway — the same reason CodeExecution and the RunPod types
 * short-circuit.
 *
 * The skill holds the questions; the caller's input holds the state. That split
 * is what makes a question set reusable across workflows and publishable to the
 * marketplace, which a node's inline config cannot be.
 */
class ExecuteDecisionSkillAction
{
    public function __construct(
        private readonly DecisionDriverResolver $resolver,
        private readonly ReserveBudgetAction $reserve,
        private readonly SettleBudgetAction $settle,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{execution: SkillExecution, output: array<string, mixed>|null}
     */
    public function execute(
        Skill $skill,
        array $input,
        string $teamId,
        string $userId,
        ?string $agentId = null,
        ?string $experimentId = null,
    ): array {
        $config = is_array($skill->configuration) ? $skill->configuration : [];
        $questions = $config['questions'] ?? null;

        if (! is_array($questions) || $questions === []) {
            return $this->fail($skill, $input, $teamId, $agentId, $experimentId,
                'Decision skill missing required configuration: a non-empty `questions` map.');
        }

        $state = $input['state'] ?? $input;
        $team = Team::find($teamId);

        $startTime = hrtime(true);

        try {
            $resolved = $this->resolver->resolve($team, $config['driver'] ?? null);
        } catch (Throwable $e) {
            return $this->fail($skill, $input, $teamId, $agentId, $experimentId, $e->getMessage());
        }

        // ExecuteSkillAction reserves and settles around its LLM call, but a
        // specialized type short-circuits before that — so the ledger entry has
        // to be written here, the same way DecisionNodeExecutor writes it.
        $reservation = null;
        if ($resolved->isBillable()) {
            $reservation = $this->reserve->execute(
                userId: $userId,
                teamId: $teamId,
                amount: $resolved->creditsPerCall,
                experimentId: $experimentId,
                description: "Decision skill: {$skill->name}",
            );
        }

        try {
            $result = $resolved->driver->decide($state, $questions);
        } catch (Throwable $e) {
            $this->releaseQuietly($reservation);

            return $this->fail($skill, $input, $teamId, $agentId, $experimentId, $e->getMessage());
        }

        $this->settle->execute($reservation, $resolved->isBillable() ? $resolved->creditsPerCall : 0);

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        $minConfidence = isset($config['min_confidence']) ? (float) $config['min_confidence'] : null;
        $answers = [];
        $lowConfidence = [];

        foreach ($result->answers as $id => $answer) {
            /** @var Answer $answer */
            $answers[$id] = $answer->toArray() + ['value' => $answer->value()];

            if ($minConfidence === null) {
                continue;
            }
            // Null is not "confident enough" — see DecisionNodeExecutor.
            $confidence = $answer->confidence();
            if ($confidence === null || $confidence < $minConfidence) {
                $lowConfidence[] = (string) $id;
            }
        }

        $output = [
            'answers' => $answers,
            'model' => $result->model,
            'latency_ms' => $result->latencyMs,
            'input_tokens' => $result->inputTokens,
            'low_confidence' => $lowConfidence,
            'decided_by' => $resolved->source,
        ];

        $execution = SkillExecution::create([
            'skill_id' => $skill->id,
            'agent_id' => $agentId,
            'experiment_id' => $experimentId,
            'team_id' => $teamId,
            'status' => 'completed',
            'input' => $input,
            'output' => $output,
            'duration_ms' => $durationMs,
            'cost_credits' => $resolved->isBillable() ? $resolved->creditsPerCall : 0,
        ]);

        $skill->recordExecution(true, $durationMs);

        return ['execution' => $execution, 'output' => $output];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{execution: SkillExecution, output: null}
     */
    private function fail(Skill $skill, array $input, string $teamId, ?string $agentId, ?string $experimentId, string $error): array
    {
        $execution = SkillExecution::create([
            'skill_id' => $skill->id,
            'agent_id' => $agentId,
            'experiment_id' => $experimentId,
            'team_id' => $teamId,
            'status' => 'failed',
            'input' => $input,
            'output' => null,
            'error_message' => $error,
            'duration_ms' => 0,
            'cost_credits' => 0,
        ]);

        $skill->recordExecution(false, 0);

        return ['execution' => $execution, 'output' => null];
    }

    /**
     * Return the whole reservation after a failed call.
     *
     * Swallowing a settlement error is deliberate: the driver error is what the
     * caller needs to see, and letting a failed release replace it hides the
     * cause. Mirrors SkillPlaygroundRunAction.
     */
    private function releaseQuietly(?CreditLedger $reservation): void
    {
        if ($reservation === null) {
            return;
        }

        try {
            $this->settle->execute($reservation, 0);
        } catch (Throwable) {
            // Do not mask the original error.
        }
    }
}
