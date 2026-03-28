<?php

namespace App\Domain\Signal\Rules;

use App\Domain\Signal\Contracts\EntityRule;
use App\Domain\Signal\DTOs\ContactRiskContext;

class S01BurstActivityRule extends EntityRule
{
    private const BURST_THRESHOLD = 50;

    public function name(): string
    {
        return 'S01';
    }

    public function label(): string
    {
        return 'Burst signal activity';
    }

    public function weight(): int
    {
        return 10;
    }

    public function evaluate(ContactRiskContext $context): bool
    {
        return $context->signalCount > self::BURST_THRESHOLD;
    }
}
