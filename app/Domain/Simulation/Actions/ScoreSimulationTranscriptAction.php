<?php

namespace App\Domain\Simulation\Actions;

use App\Domain\Evaluation\Services\LlmJudge;

/**
 * Scores a simulated conversation against evaluation criteria, reusing the
 * Evaluation domain's LlmJudge (0–10 per criterion). A criterion that throws
 * (unknown / judge error) is recorded as 0 rather than aborting the score.
 */
class ScoreSimulationTranscriptAction
{
    public function __construct(private readonly LlmJudge $judge) {}

    /**
     * @param  list<array{role: string, content: string}>  $conversation
     * @param  list<string>  $criteria
     * @return array<string, array{score: float, reasoning: string}>
     */
    public function execute(array $conversation, array $criteria, string $teamId): array
    {
        $input = $this->renderRole($conversation, 'user');
        $output = $this->renderRole($conversation, 'agent');
        $scores = [];

        foreach ($criteria as $criterion) {
            try {
                $result = $this->judge->evaluate($criterion, $input, $output, null, null, null, $teamId);
                $scores[$criterion] = [
                    'score' => (float) $result['score'],
                    'reasoning' => (string) ($result['reasoning'] ?? ''),
                ];
            } catch (\Throwable $e) {
                $scores[$criterion] = [
                    'score' => 0.0,
                    'reasoning' => 'scoring failed: '.$e->getMessage(),
                ];
            }
        }

        return $scores;
    }

    /**
     * @param  list<array{role: string, content: string}>  $conversation
     */
    private function renderRole(array $conversation, string $role): string
    {
        $lines = [];

        foreach ($conversation as $turn) {
            if ($turn['role'] === $role) {
                $lines[] = $turn['content'];
            }
        }

        return implode("\n", $lines);
    }
}
