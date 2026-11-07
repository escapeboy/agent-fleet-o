<?php

namespace App\Domain\Skill\Enums;

use Illuminate\Support\Facades\Log;

enum RiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function requiresApproval(): bool
    {
        return $this === self::High || $this === self::Critical;
    }

    /**
     * Lenient mapping for risk levels coming from marketplace listings and manifests.
     * Missing/empty values default to Low, the legacy 'safe' alias maps to Low, and
     * anything unknown falls back to the conservative High (requires approval).
     */
    public static function fromMarketplace(mixed $value): self
    {
        if ($value === null) {
            return self::Low;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if ($normalized === '') {
                return self::Low;
            }

            $level = self::tryFrom($normalized) ?? match ($normalized) {
                'safe' => self::Low,
                default => null,
            };

            if ($level !== null) {
                return $level;
            }
        }

        Log::warning('Unknown marketplace risk_level, defaulting to high', ['value' => $value]);

        return self::High;
    }
}
