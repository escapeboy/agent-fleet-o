<?php

namespace App\Domain\Simulation\Actions;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Simulation\Enums\SimulationStatus;
use App\Domain\Simulation\Models\SimulationPersona;
use App\Domain\Simulation\Models\SimulationRun;
use App\Domain\Simulation\Models\SimulationSuite;
use App\Domain\Simulation\Models\SimulationTranscript;

/**
 * Orchestrates a simulation run: drives each persona through a multi-turn
 * conversation against the target agent, scores the transcript, and aggregates
 * a pass/fail matrix. A single persona failing never aborts the whole run.
 *
 * Budget is bounded by the gateway's own BudgetEnforcement middleware plus the
 * per-call maxCostCredits ceiling set on each simulator/judge request.
 */
class RunSimulationAction
{
    public function __construct(
        private readonly SimulateUserTurnAction $userTurn,
        private readonly ScoreSimulationTranscriptAction $scorer,
        private readonly ExecuteAgentAction $executeAgent,
    ) {}

    public function execute(SimulationRun $run): SimulationRun
    {
        /** @var SimulationSuite $suite */
        $suite = $run->suite;
        $agent = Agent::withoutGlobalScopes()->find($suite->target_id);

        if ($agent === null) {
            return $this->fail($run, 'Target agent not found.');
        }

        $personas = $suite->personas;

        if ($personas->isEmpty()) {
            return $this->fail($run, 'No personas to simulate — generate personas first.');
        }

        $run->update(['status' => SimulationStatus::Running, 'started_at' => now()]);

        $maxTurns = min((int) $suite->max_turns, (int) config('simulation.caps.turns', 8));
        $threshold = (float) ($suite->pass_threshold ?? config('simulation.defaults.pass_threshold', 6.0));
        /** @var list<string> $criteria */
        $criteria = $suite->criteria ?: config('simulation.defaults.criteria', ['relevance', 'correctness']);
        $userId = $this->resolveUserId($run, $suite);

        $passed = 0;
        $failed = 0;

        foreach ($personas as $persona) {
            $transcript = $this->runPersona($run, $suite, $agent, $persona, $maxTurns, $criteria, $threshold, $userId);
            $transcript->verdict === 'pass' ? $passed++ : $failed++;
        }

        $run->update([
            'status' => SimulationStatus::Completed,
            'finished_at' => now(),
            'aggregate' => [
                'personas' => $personas->count(),
                'passed' => $passed,
                'failed' => $failed,
                'criteria' => $criteria,
                'pass_threshold' => $threshold,
            ],
        ]);

        return $run->refresh();
    }

    /**
     * @param  list<string>  $criteria
     */
    private function runPersona(
        SimulationRun $run,
        SimulationSuite $suite,
        Agent $agent,
        SimulationPersona $persona,
        int $maxTurns,
        array $criteria,
        float $threshold,
        string $userId,
    ): SimulationTranscript {
        $conversation = [];
        $scores = [];
        $verdict = 'fail';
        $failedTurnIndex = null;

        try {
            for ($turn = 1; $turn <= $maxTurns; $turn++) {
                $userMsg = $this->userTurn->execute($persona, $conversation, $suite->team_id, $userId);
                $conversation[] = ['role' => 'user', 'content' => $userMsg];

                $result = $this->executeAgent->execute($agent, ['message' => $userMsg], $suite->team_id, $userId);
                $conversation[] = ['role' => 'agent', 'content' => $this->normalizeOutput($result['output'] ?? null)];
            }

            $scores = $this->scorer->execute($conversation, $criteria, $suite->team_id);

            if ($this->passes($scores, $threshold)) {
                $verdict = 'pass';
            } else {
                $failedTurnIndex = $this->lastAgentIndex($conversation);
            }
        } catch (\Throwable $e) {
            $verdict = 'fail';
            $failedTurnIndex = $this->lastAgentIndex($conversation);
            $scores['_error'] = ['score' => 0.0, 'reasoning' => $e->getMessage()];
        }

        return SimulationTranscript::create([
            'team_id' => $suite->team_id,
            'run_id' => $run->id,
            'persona_id' => $persona->id,
            'turns' => $conversation,
            'scores' => $scores,
            'verdict' => $verdict,
            'failed_turn_index' => $failedTurnIndex,
        ]);
    }

    /**
     * @param  array<string, array{score: float, reasoning: string}>  $scores
     */
    private function passes(array $scores, float $threshold): bool
    {
        if ($scores === []) {
            return false;
        }

        foreach ($scores as $entry) {
            if ($entry['score'] < $threshold) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{role: string, content: string}>  $conversation
     */
    private function lastAgentIndex(array $conversation): ?int
    {
        for ($i = count($conversation) - 1; $i >= 0; $i--) {
            if (($conversation[$i]['role'] ?? '') === 'agent') {
                return $i;
            }
        }

        return null;
    }

    private function normalizeOutput(mixed $output): string
    {
        if (is_string($output)) {
            return $output;
        }

        if (is_array($output)) {
            $text = $output['result'] ?? $output['response'] ?? $output['question'] ?? null;

            if (is_string($text)) {
                return $text;
            }

            return (string) json_encode($output);
        }

        return '';
    }

    private function resolveUserId(SimulationRun $run, SimulationSuite $suite): string
    {
        $userId = $run->created_by
            ?? $suite->created_by
            ?? Team::withoutGlobalScopes()->find($suite->team_id)?->owner_id;

        return (string) $userId;
    }

    private function fail(SimulationRun $run, string $error): SimulationRun
    {
        $run->update([
            'status' => SimulationStatus::Failed,
            'finished_at' => now(),
            'error' => $error,
        ]);

        return $run->refresh();
    }
}
