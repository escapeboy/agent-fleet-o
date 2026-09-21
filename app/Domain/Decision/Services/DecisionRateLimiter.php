<?php

namespace App\Domain\Decision\Services;

use Illuminate\Support\Sleep;

/**
 * Keeps an eval run under the published Jev ceilings (1,200 requests per minute
 * and 250,000 tokens per second) by sleeping before a request that would cross
 * either one. Configured a little below the real limits so a burst of retries
 * does not push the run over.
 *
 * Two sliding windows rather than a token bucket: requests over the last 60s,
 * tokens over the last 1s.
 *
 * @internal Single-process. An eval spread over several processes would need a
 *           shared store; the harness deliberately runs in one.
 */
final class DecisionRateLimiter
{
    /** @var list<float> */
    private array $requestTimes = [];

    /** @var list<array{0: float, 1: int}> */
    private array $tokenEvents = [];

    public function __construct(
        private readonly int $requestsPerMinute,
        private readonly int $tokensPerSecond,
    ) {}

    /**
     * Blocks until sending `$estimatedTokens` more tokens keeps both windows legal.
     */
    public function acquire(int $estimatedTokens): void
    {
        while (true) {
            $now = microtime(true);
            $this->prune($now);

            $waitForRequests = $this->waitForRequestSlot($now);
            $waitForTokens = $this->waitForTokenBudget($now, $estimatedTokens);
            $wait = max($waitForRequests, $waitForTokens);

            if ($wait <= 0) {
                $this->requestTimes[] = $now;
                $this->tokenEvents[] = [$now, $estimatedTokens];

                return;
            }

            Sleep::usleep((int) ceil($wait * 1_000_000));
        }
    }

    private function waitForRequestSlot(float $now): float
    {
        if (count($this->requestTimes) < $this->requestsPerMinute) {
            return 0.0;
        }

        return ($this->requestTimes[0] + 60.0) - $now;
    }

    private function waitForTokenBudget(float $now, int $estimatedTokens): float
    {
        $used = array_sum(array_column($this->tokenEvents, 1));

        if ($used + $estimatedTokens <= $this->tokensPerSecond) {
            return 0.0;
        }

        return $this->tokenEvents === [] ? 0.0 : ($this->tokenEvents[0][0] + 1.0) - $now;
    }

    private function prune(float $now): void
    {
        $this->requestTimes = array_values(array_filter(
            $this->requestTimes,
            static fn (float $t): bool => $t > $now - 60.0,
        ));

        $this->tokenEvents = array_values(array_filter(
            $this->tokenEvents,
            static fn (array $e): bool => $e[0] > $now - 1.0,
        ));
    }
}
