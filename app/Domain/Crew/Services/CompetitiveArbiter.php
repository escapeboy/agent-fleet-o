<?php

namespace App\Domain\Crew\Services;

use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;

/**
 * Competitive coordination (idea D): instead of synthesizing all member
 * outputs into one merged result, members each produce a candidate and a
 * deterministic arbiter selects the single best one. Distinct from Fanout's
 * LLM synthesis (merge) and Adversarial debate (challenge rounds).
 *
 * Deterministic by design — scoring is derived from the candidate payloads,
 * so the selection is predictable, testable, and free (no LLM call). Opt-in
 * via crew settings['arbitration_enabled']; reuses the Fanout broadcast +
 * gather plumbing, only swapping the final reduction step.
 */
class CompetitiveArbiter
{
    /**
     * @return array{result: array<string, mixed>, cost: int}
     */
    public function arbitrate(CrewExecution $execution): array
    {
        $candidates = $execution->taskExecutions()
            ->whereIn('status', ['validated', 'completed'])
            ->get()
            ->filter(fn (CrewTaskExecution $t) => ! empty($t->output))
            ->values();

        if ($candidates->isEmpty()) {
            return [
                'result' => ['_arbitration' => ['winner' => null, 'reason' => 'No validated candidates to arbitrate.', 'candidates' => 0]],
                'cost' => 0,
            ];
        }

        $ranked = $candidates
            ->map(fn (CrewTaskExecution $t, int $i) => [
                'task' => $t,
                'index' => $i,
                'score' => $this->score($t),
            ])
            // Highest score wins; stable tie-break on original order (earliest).
            ->sortBy([['score', 'desc'], ['index', 'asc']])
            ->values();

        $winner = $ranked->first();
        $winnerTask = $winner['task'];

        $result = is_array($winnerTask->output) ? $winnerTask->output : ['output' => $winnerTask->output];
        $result['_arbitration'] = [
            'winner' => [
                'task_id' => $winnerTask->id,
                'agent_id' => $winnerTask->agent_id,
                'score' => $winner['score'],
            ],
            'candidates' => $candidates->count(),
            'ranking' => $ranked->map(fn ($r) => [
                'task_id' => $r['task']->id,
                'agent_id' => $r['task']->agent_id,
                'score' => $r['score'],
            ])->all(),
            'reason' => "Selected highest-scoring candidate ({$winner['score']}) of {$candidates->count()}.",
        ];

        return ['result' => $result, 'cost' => 0];
    }

    /**
     * Deterministic candidate score (higher = better):
     *  - hard fail (error marker / empty) → 0
     *  - completeness: longer, structured output scores higher (bucketed)
     *  - structure bonus for an explicit success flag.
     */
    private function score(CrewTaskExecution $task): int
    {
        $output = $task->output ?? [];

        if (($output['error'] ?? null) || ($output['success'] ?? null) === false) {
            return 0;
        }

        $text = is_array($output) ? (string) json_encode($output) : (string) $output;
        $len = strlen(trim($text));

        if ($len <= 2) {
            return 0;
        }

        $score = match (true) {
            $len >= 2000 => 5,
            $len >= 600 => 4,
            $len >= 150 => 3,
            $len >= 30 => 2,
            default => 1,
        };

        if (($output['success'] ?? null) === true) {
            $score++;
        }

        return $score;
    }
}
