<?php

namespace App\Domain\Signal\Rules;

use App\Domain\Signal\Contracts\EntityRule;
use App\Domain\Signal\DTOs\ContactRiskContext;

class S02NoVerifiedChannelRule extends EntityRule
{
    public function name(): string
    {
        return 'S02';
    }

    public function label(): string
    {
        return 'No verified channel';
    }

    public function weight(): int
    {
        return 10;
    }

    public function evaluate(ContactRiskContext $context): bool
    {
        return ! $context->hasVerifiedChannel;
    }
}
