<?php

namespace App\Infrastructure\AI\Enums;

enum ReasoningEffort: string
{
    /** No extended thinking — fastest, cheapest */
    case None = 'none';

    /** ~2K token thinking budget — simple analysis, summarisation */
    case Low = 'low';

    /** ~8K token thinking budget — multi-step reasoning, standard tasks */
    case Medium = 'medium';

    /** ~32K token thinking budget — complex architecture, deep code analysis */
    case High = 'high';

    /** Let BudgetPressureRouting pick based on classified complexity */
    case Auto = 'auto';

    /** Token budget for this effort level. Returns null for None and Auto (Auto is resolved at runtime). */
    public function toBudgetTokens(): ?int
    {
        return match ($this) {
            self::None => null,
            self::Low => 2_000,
            self::Medium => 8_000,
            self::High => 32_000,
            self::Auto => null,
        };
    }

    /** Resolve Auto effort to a concrete budget based on classified complexity. */
    public static function fromComplexity(RequestComplexity $complexity): ?int
    {
        return match ($complexity) {
            RequestComplexity::Light => null,
            RequestComplexity::Standard => self::Low->toBudgetTokens(),
            RequestComplexity::Heavy => self::Medium->toBudgetTokens(),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'None (disabled)',
            self::Low => 'Low (~2K tokens)',
            self::Medium => 'Medium (~8K tokens)',
            self::High => 'High (~32K tokens)',
            self::Auto => 'Auto (by task complexity)',
        };
    }
}
