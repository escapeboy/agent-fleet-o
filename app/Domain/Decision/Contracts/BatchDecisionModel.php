<?php

namespace App\Domain\Decision\Contracts;

use App\Domain\Decision\DTOs\DecisionResult;

/**
 * A driver that can answer several independent cases in one round of parallel
 * requests. The eval harness uses it to fill the --concurrency window; drivers
 * that do not implement it are simply run one case at a time.
 */
interface BatchDecisionModel extends DecisionModel
{
    /**
     * @param  array<string, array{state: array<string, mixed>|list<mixed>|string, questions: array<string, array<string, mixed>>}>  $requests
     * @return array<string, DecisionResult|\Throwable> keyed by the same keys, in no guaranteed order
     */
    public function decideBatch(array $requests): array;
}
