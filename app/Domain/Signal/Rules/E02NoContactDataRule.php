<?php

namespace App\Domain\Signal\Rules;

use App\Domain\Signal\Contracts\EntityRule;
use App\Domain\Signal\DTOs\ContactRiskContext;

class E02NoContactDataRule extends EntityRule
{
    public function name(): string
    {
        return 'E02';
    }

    public function label(): string
    {
        return 'No email or phone';
    }

    public function weight(): int
    {
        return 10;
    }

    public function evaluate(ContactRiskContext $context): bool
    {
        return $context->contact->email === null && $context->contact->phone === null;
    }
}
