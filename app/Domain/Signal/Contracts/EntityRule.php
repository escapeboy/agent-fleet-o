<?php

namespace App\Domain\Signal\Contracts;

use App\Domain\Signal\DTOs\ContactRiskContext;

abstract class EntityRule
{
    abstract public function name(): string;

    abstract public function label(): string;

    /**
     * Weight added to the risk score when the rule triggers.
     * Common values: 0 (informational), 10 (medium), 20 (high), 70 (extreme).
     */
    abstract public function weight(): int;

    abstract public function evaluate(ContactRiskContext $context): bool;
}
