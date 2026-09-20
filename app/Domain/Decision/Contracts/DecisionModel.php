<?php

namespace App\Domain\Decision\Contracts;

use App\Domain\Decision\DTOs\DecisionResult;

/**
 * A decision model evaluates typed questions against a state and returns one
 * typed answer per question. No text generation: every implementation must
 * return structured values the caller can branch on.
 */
interface DecisionModel
{
    /**
     * @param  array<string, mixed>|list<mixed>|string  $state
     * @param  array<string, array<string, mixed>>  $questions  question id => {type, instructions, criteria?}
     */
    public function decide(array|string $state, array $questions): DecisionResult;

    /**
     * The model id this driver sends (and the eval records).
     */
    public function model(): string;
}
