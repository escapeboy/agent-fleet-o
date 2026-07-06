<?php

namespace App\Infrastructure\AI\LoopDetection;

use App\Domain\Agent\Models\AiRun;
use App\Infrastructure\AI\LoopDetection\Contracts\TurnHistoryStore;
use App\Infrastructure\AI\LoopDetection\Enums\LoopSignalType;

/**
 * Proactive runaway-agent detector. Records each LLM turn into a per-context
 * ring buffer and evaluates three deterministic circuit breakers against the
 * recent window: runaway velocity, stalled (repeating) output, and semantic
 * prompt loop. Cheap and side-effect-free apart from the history write.
 */
class LoopDetector
{
    public function __construct(private readonly TurnHistoryStore $store) {}

    public function inspect(AiRun $run): LoopSignal
    {
        $contextKey = $this->contextKey($run);

        if ($contextKey === null) {
            return LoopSignal::none();
        }

        return $this->inspectTurn(
            $contextKey,
            $this->promptText($run),
            $this->outputText($run),
            microtime(true),
        );
    }

    public function inspectTurn(string $contextKey, string $promptText, string $outputText, float $now): LoopSignal
    {
        if (! (bool) config('loop_detection.enabled', false)) {
            return LoopSignal::none();
        }

        $window = max(2, (int) config('loop_detection.window', 8));
        $ttl = max(60, (int) config('loop_detection.ttl_seconds', 1800));

        $bigrams = SimilarityScorer::bigrams($promptText);
        $outputHash = sha1(trim(mb_strtolower($outputText)));

        $recent = $this->store->recent($contextKey);

        $signal = $this->evaluate($recent, $bigrams, $outputHash, $now);

        $this->store->push($contextKey, [
            'bg' => $bigrams,
            'oh' => $outputHash,
            't' => $now,
        ], $window, $ttl);

        return $signal;
    }

    /**
     * @param  list<array{bg?: list<string>, oh?: string, t?: float}>  $recent
     * @param  list<string>  $bigrams
     */
    private function evaluate(array $recent, array $bigrams, string $outputHash, float $now): LoopSignal
    {
        // 1. Runaway velocity — too many turns in a short window.
        $velWindow = max(1, (int) config('loop_detection.velocity.window_seconds', 60));
        $velMax = max(1, (int) config('loop_detection.velocity.max_turns', 30));

        $inWindow = 1; // include the current turn

        foreach ($recent as $turn) {
            if (($now - (float) ($turn['t'] ?? 0.0)) <= $velWindow) {
                $inWindow++;
            }
        }

        if ($inWindow > $velMax) {
            return new LoopSignal(
                LoopSignalType::Runaway,
                "velocity {$inWindow} turns within {$velWindow}s exceeds max {$velMax}",
                (float) $inWindow,
                ['turns' => $inWindow, 'window_seconds' => $velWindow, 'max_turns' => $velMax],
            );
        }

        // 2. Stall — the same output repeated consecutively.
        $stallThreshold = max(2, (int) config('loop_detection.stall.repeat_threshold', 3));

        $identical = 1; // include the current turn

        foreach ($recent as $turn) {
            if (($turn['oh'] ?? null) === $outputHash) {
                $identical++;
            } else {
                break;
            }
        }

        if ($identical >= $stallThreshold) {
            return new LoopSignal(
                LoopSignalType::Stall,
                "identical output repeated {$identical} times",
                1.0,
                ['repeats' => $identical, 'threshold' => $stallThreshold],
            );
        }

        // 3. Semantic loop — N consecutive near-identical prompts.
        $simThreshold = (float) config('loop_detection.similarity.threshold', 0.9);
        $minTurns = max(2, (int) config('loop_detection.similarity.min_turns', 3));
        $needPrior = $minTurns - 1;

        if ($bigrams !== [] && count($recent) >= $needPrior) {
            $minScore = 1.0;
            $allSimilar = true;

            for ($i = 0; $i < $needPrior; $i++) {
                $priorBigrams = $recent[$i]['bg'] ?? [];
                $score = SimilarityScorer::jaccard($bigrams, is_array($priorBigrams) ? $priorBigrams : []);
                $minScore = min($minScore, $score);

                if ($score < $simThreshold) {
                    $allSimilar = false;
                    break;
                }
            }

            if ($allSimilar) {
                return new LoopSignal(
                    LoopSignalType::SemanticLoop,
                    "prompt similarity >= {$simThreshold} across {$minTurns} consecutive turns",
                    $minScore,
                    ['min_score' => round($minScore, 4), 'turns' => $minTurns, 'threshold' => $simThreshold],
                );
            }
        }

        return LoopSignal::none();
    }

    private function contextKey(AiRun $run): ?string
    {
        if ($run->experiment_id) {
            return 'exp:'.$run->experiment_id;
        }

        if ($run->agent_id) {
            return 'agent:'.$run->agent_id.':team:'.($run->team_id ?? 'none');
        }

        if ($run->team_id) {
            return 'team:'.$run->team_id;
        }

        return null;
    }

    private function promptText(AiRun $run): string
    {
        $snapshot = $run->prompt_snapshot ?? [];

        if (! is_array($snapshot)) {
            return '';
        }

        $system = (string) ($snapshot['system'] ?? '');
        $user = (string) ($snapshot['user'] ?? '');

        return trim($system."\n".$user);
    }

    private function outputText(AiRun $run): string
    {
        $raw = $run->raw_output ?? [];

        if (is_array($raw)) {
            return isset($raw['text']) ? (string) $raw['text'] : (string) json_encode($raw);
        }

        return (string) $raw;
    }
}
