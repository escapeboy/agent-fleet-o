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

        /** @var array<string, mixed> $result */
        $result = $winnerTask->output ?? [];
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
        // output is jsonb (cast 'array'); larastan widens it to string, so pin
        // the working type to an open string-keyed array for safe offset access.
        /** @var array<string, mixed> $output */
        $output = $task->output ?? [];

        // Capture the flags once up-front (offsets read while the type is a
        // plain open array) and reuse them below.
        $errorFlag = $output['error'] ?? null;
        $successFlag = $output['success'] ?? null;

        if ($errorFlag || $successFlag === false) {
            return 0;
        }

        $text = (string) json_encode($output);
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

        if ($successFlag === true) {
            $score++;
        }

        return $score;
    }
}
