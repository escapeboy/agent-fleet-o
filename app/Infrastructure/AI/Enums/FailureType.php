<?php

namespace App\Infrastructure\AI\Enums;

enum FailureType: string
{
    case QualityFailure = 'quality_failure';
    case RateLimit = 'rate_limit';
    case AuthError = 'auth_error';
    case Timeout = 'timeout';
    case ProviderError = 'provider_error';
    case BudgetExhausted = 'budget_exhausted';

    /**
     * Quality failures warrant model escalation; other failures use provider fallback.
     */
    public function shouldEscalateModel(): bool
    {
        return $this === self::QualityFailure;
    }
}
