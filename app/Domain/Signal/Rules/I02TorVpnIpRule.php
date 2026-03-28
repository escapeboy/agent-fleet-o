<?php

namespace App\Domain\Signal\Rules;

use App\Domain\Signal\Contracts\EntityRule;
use App\Domain\Signal\DTOs\ContactRiskContext;

class I02TorVpnIpRule extends EntityRule
{
    public function name(): string
    {
        return 'I02';
    }

    public function label(): string
    {
        return 'Tor or VPN source';
    }

    public function weight(): int
    {
        return 20;
    }

    public function evaluate(ContactRiskContext $context): bool
    {
        return $context->ipReputation !== null
            && ($context->ipReputation->isTor || $context->ipReputation->isVpn);
    }
}
