<?php

namespace App\Domain\Workflow\Executors;

use App\Domain\Budget\Actions\ReserveBudgetAction;
use App\Domain\Budget\Actions\SettleBudgetAction;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Decision\DTOs\Answer;
use App\Domain\Decision\DTOs\DecisionResult;
use App\Domain\Decision\Services\DecisionDriverResolver;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Contracts\NodeExecutorInterface;
use App\Domain\Workflow\Models\WorkflowNode;
use Throwable;

/**
 * Asks a decision model a set of typed questions about the run's state and
 * writes the typed answers into the step output so downstream edges can branch
 * on them.
 *
 * This is the gap between a Conditional node (deterministic comparisons, cannot
 * judge) and an Llm node (judges, but in prose, priced in tokens and measured in
 * seconds). One call answers every question, and each answer carries a
 * confidence the author can route on.
 */
class DecisionNodeExecutor implements NodeExecutorInterface
{
    use InterpolatesTemplates;

    public function __construct(
        private readonly DecisionDriverResolver $resolver,
        private readonly ReserveBudgetAction $reserve,
        private readonly SettleBudgetAction $settle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(WorkflowNode $node, PlaybookStep $step, Experiment $experiment): array
    {
        $config = $this->parseConfig($node->config);

        $questions = $config['questions'] ?? null;
        if (! is_array($questions) || $questions === []) {
            return ['error' => 'Decision node requires a non-empty `questions` map in its config.'];
        }

        $context = $this->buildStepContext($step, $experiment);
        $state = $this->interpolate((string) ($config['state'] ?? '{{input}}'), $context);

        try {
            $team = Team::find($experiment->team_id);
            $resolved = $this->resolver->resolve($team, $config['driver'] ?? null);
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        $reservation = null;
        if ($resolved->isBillable()) {
            $reservation = $this->reserve->execute(
                userId: (string) ($experiment->user_id ?? ''),
                teamId: (string) $experiment->team_id,
                amount: $resolved->creditsPerCall,
                experimentId: $experiment->id,
                description: "Decision call ({$resolved->name})",
            );
        }

        try {
            $result = $resolved->driver->decide($state, $questions);
        } catch (Throwable $e) {
            $this->releaseQuietly($reservation);

            return ['error' => $e->getMessage()];
        }

        $this->settle->execute($reservation, $resolved->isBillable() ? $resolved->creditsPerCall : 0);

        return $this->shapeOutput($result, $config, $resolved->source);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function shapeOutput(DecisionResult $result, array $config, string $source): array
    {
        $minConfidence = isset($config['min_confidence']) ? (float) $config['min_confidence'] : null;

        $answers = [];
        $lowConfidence = [];

        foreach ($result->answers as $id => $answer) {
            /** @var Answer $answer */
            $answers[$id] = $answer->toArray() + ['value' => $answer->value()];

            if ($minConfidence === null) {
                continue;
            }

            // A null confidence is NOT "confident enough". NoulAnswer returns null
            // by construction, so treating null as passing would route every
            // score-style answer straight past the escalation edge.
            $confidence = $answer->confidence();
            if ($confidence === null || $confidence < $minConfidence) {
                $lowConfidence[] = (string) $id;
            }
        }

        return [
            'answers' => $answers,
            // The model, not just the driver name: config/decision.php warns that
            // a version bump invalidates every confidence threshold tuned against
            // the previous one, so a stored decision that cannot name its model
            // is not auditable.
            'model' => $result->model,
            'latency_ms' => $result->latencyMs,
            'input_tokens' => $result->inputTokens,
            'low_confidence' => $lowConfidence,
            'decided_by' => $source,
        ];
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
