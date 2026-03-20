<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\Services\TokenEstimator;
use Tests\TestCase;

class TokenEstimatorTest extends TestCase
{
    private TokenEstimator $estimator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estimator = new TokenEstimator;
    }

    public function test_empty_string_returns_zero(): void
    {
        $this->assertSame(0, $this->estimator->estimate(''));
    }

    public function test_estimates_natural_language_at_4_chars_per_token(): void
    {
        // 100 chars / 4 = 25 tokens
        $text = str_repeat('abcd', 25);
        $this->assertSame(25, $this->estimator->estimate($text));
    }

    public function test_estimates_structured_content_at_3_5_chars_per_token(): void
    {
        // 35 chars / 3.5 = 10 tokens
        $json = str_repeat('{"a":1', 5).'12345';
        $expected = (int) ceil(mb_strlen($json) / 3.5);
        $this->assertSame($expected, $this->estimator->estimate($json, isStructured: true));
    }

    public function test_estimate_request_adds_overhead(): void
    {
        $system = str_repeat('x', 40); // 10 tokens
        $user = str_repeat('x', 80);   // 20 tokens

        // 10 + 20 + 8 (overhead) = 38
        $this->assertSame(38, $this->estimator->estimateRequest($system, $user));
    }

    public function test_known_model_context_limit(): void
    {
        $this->assertSame(200_000, $this->estimator->getModelContextLimit('claude-sonnet-4-5'));
        $this->assertSame(128_000, $this->estimator->getModelContextLimit('gpt-4o'));
        $this->assertSame(1_048_576, $this->estimator->getModelContextLimit('gemini-2.5-flash'));
    }

    public function test_versioned_model_matches_base(): void
    {
        $this->assertSame(200_000, $this->estimator->getModelContextLimit('claude-sonnet-4-5-20250929'));
    }

    public function test_unknown_model_returns_default(): void
    {
        $this->assertSame(128_000, $this->estimator->getModelContextLimit('unknown-model'));
    }

    public function test_calculate_utilization(): void
    {
        // Small request against a 200K context model should be very low utilization
        $utilization = $this->estimator->calculateUtilization('Hello', 'World', 'claude-sonnet-4-5');
        $this->assertLessThan(0.01, $utilization);
    }

    public function test_calculate_utilization_large_prompt(): void
    {
        // Create a ~100K token prompt (400K chars / 4 chars per token)
        $largePrompt = str_repeat('x', 400_000);
        $utilization = $this->estimator->calculateUtilization('', $largePrompt, 'claude-sonnet-4-5');

        // 100K / 200K = ~0.50
        $this->assertGreaterThan(0.45, $utilization);
        $this->assertLessThan(0.55, $utilization);
    }
}
