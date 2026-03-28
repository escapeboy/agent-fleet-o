<?php

namespace App\Domain\Signal\Rules;

use App\Domain\Signal\Contracts\EntityRule;
use App\Domain\Signal\DTOs\ContactRiskContext;

class I01HighRiskIpRule extends EntityRule
{
    public function name(): string
    {
        return 'I01';
    }

    public function label(): string
    {
        return 'High-risk IP source';
    }

    public function weight(): int
    {
        return 20;
    }

    public function evaluate(ContactRiskContext $context): bool
    {
        $threshold = config('security.ip_reputation.block_threshold', 75);

        return $context->ipReputation !== null
            && $context->ipReputation->isHighRisk($threshold);
    }
}
