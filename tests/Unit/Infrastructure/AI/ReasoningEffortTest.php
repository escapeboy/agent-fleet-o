<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\Enums\ReasoningEffort;
use App\Infrastructure\AI\Enums\RequestComplexity;
use PHPUnit\Framework\TestCase;

class ReasoningEffortTest extends TestCase
{
    public function test_concrete_effort_levels_map_to_budget_tokens(): void
    {
        $this->assertNull(ReasoningEffort::None->toBudgetTokens());
        $this->assertSame(2_000, ReasoningEffort::Low->toBudgetTokens());
        $this->assertSame(8_000, ReasoningEffort::Medium->toBudgetTokens());
        $this->assertSame(32_000, ReasoningEffort::High->toBudgetTokens());
    }

    public function test_auto_returns_null_budget_for_runtime_resolution(): void
    {
        $this->assertNull(ReasoningEffort::Auto->toBudgetTokens());
    }

    public function test_auto_resolves_by_classified_complexity(): void
    {
        $this->assertNull(ReasoningEffort::fromComplexity(RequestComplexity::Light));
        $this->assertSame(2_000, ReasoningEffort::fromComplexity(RequestComplexity::Standard));
        $this->assertSame(8_000, ReasoningEffort::fromComplexity(RequestComplexity::Heavy));
    }

    public function test_all_effort_cases_have_a_label(): void
    {
        foreach (ReasoningEffort::cases() as $case) {
            $this->assertNotSame('', $case->label());
        }
    }

    public function test_try_from_empty_string_returns_null(): void
    {
        $this->assertNull(ReasoningEffort::tryFrom(''));
    }

    public function test_try_from_valid_value_returns_case(): void
    {
        $this->assertSame(ReasoningEffort::Auto, ReasoningEffort::tryFrom('auto'));
        $this->assertSame(ReasoningEffort::None, ReasoningEffort::tryFrom('none'));
    }
}
