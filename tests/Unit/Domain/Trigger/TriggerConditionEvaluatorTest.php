<?php

namespace Tests\Unit\Domain\Trigger;

use App\Domain\Signal\Models\Signal;
use App\Domain\Trigger\Services\TriggerConditionEvaluator;
use PHPUnit\Framework\TestCase;

class TriggerConditionEvaluatorTest extends TestCase
{
    private TriggerConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new TriggerConditionEvaluator;
    }

    private function makeSignal(array $payload): Signal
    {
        $signal = new Signal;
        $signal->payload = $payload;

        return $signal;
    }

    public function test_empty_conditions_always_match(): void
    {
        $signal = $this->makeSignal(['event' => 'order.placed']);

        $this->assertTrue($this->evaluator->evaluate(null, $signal));
        $this->assertTrue($this->evaluator->evaluate([], $signal));
    }

    public function test_eq_operator_strict_equality(): void
    {
        $signal = $this->makeSignal(['status' => 'active']);

        $this->assertTrue($this->evaluator->evaluate(['status' => ['eq' => 'active']], $signal));
        $this->assertFalse($this->evaluator->evaluate(['status' => ['eq' => 'inactive']], $signal));
    }

    public function test_eq_no_type_coercion_between_string_and_int(): void
    {
        // "1" (string) must not match 1 (int) — strict equality
        $signal = $this->makeSignal(['count' => '1']);

        $this->assertFalse($this->evaluator->evaluate(['count' => ['eq' => 1]], $signal));
        $this->assertTrue($this->evaluator->evaluate(['count' => ['eq' => '1']], $signal));
    }

    public function test_neq_operator(): void
    {
        $signal = $this->makeSignal(['status' => 'active']);

        $this->assertTrue($this->evaluator->evaluate(['status' => ['neq' => 'inactive']], $signal));
        $this->assertFalse($this->evaluator->evaluate(['status' => ['neq' => 'active']], $signal));
    }

    public function test_gte_operator(): void
    {
        $signal = $this->makeSignal(['priority' => 5]);

        $this->assertTrue($this->evaluator->evaluate(['priority' => ['gte' => 5]], $signal));
        $this->assertTrue($this->evaluator->evaluate(['priority' => ['gte' => 3]], $signal));
        $this->assertFalse($this->evaluator->evaluate(['priority' => ['gte' => 6]], $signal));
    }

    public function test_lte_operator(): void
    {
        $signal = $this->makeSignal(['priority' => 5]);

        $this->assertTrue($this->evaluator->evaluate(['priority' => ['lte' => 5]], $signal));
        $this->assertTrue($this->evaluator->evaluate(['priority' => ['lte' => 10]], $signal));
        $this->assertFalse($this->evaluator->evaluate(['priority' => ['lte' => 4]], $signal));
    }

    public function test_gte_returns_false_for_null_value(): void
    {
        $signal = $this->makeSignal(['other' => 'x']);

        // Missing field returns null — gte/lte should fail closed
        $this->assertFalse($this->evaluator->evaluate(['priority' => ['gte' => 0]], $signal));
    }

    public function test_contains_operator_on_string(): void
    {
        $signal = $this->makeSignal(['message' => 'Hello World']);

        $this->assertTrue($this->evaluator->evaluate(['message' => ['contains' => 'world']], $signal));
        $this->assertTrue($this->evaluator->evaluate(['message' => ['contains' => 'Hello']], $signal));
        $this->assertFalse($this->evaluator->evaluate(['message' => ['contains' => 'goodbye']], $signal));
    }

    public function test_not_contains_operator(): void
    {
        $signal = $this->makeSignal(['message' => 'Hello World']);

        $this->assertTrue($this->evaluator->evaluate(['message' => ['not_contains' => 'goodbye']], $signal));
        $this->assertFalse($this->evaluator->evaluate(['message' => ['not_contains' => 'world']], $signal));
    }

    public function test_exists_operator_on_present_key(): void
    {
        $signal = $this->makeSignal(['metadata' => ['severity' => 'high']]);

        $this->assertTrue($this->evaluator->evaluate(['metadata' => ['exists' => true]], $signal));
        $this->assertFalse($this->evaluator->evaluate(['metadata' => ['exists' => false]], $signal));
    }

    public function test_exists_operator_on_missing_key(): void
    {
        $signal = $this->makeSignal(['event' => 'test']);

        $this->assertTrue($this->evaluator->evaluate(['missing_key' => ['exists' => false]], $signal));
        $this->assertFalse($this->evaluator->evaluate(['missing_key' => ['exists' => true]], $signal));
    }

    public function test_dot_notation_nested_path(): void
    {
        $signal = $this->makeSignal(['metadata' => ['title' => 'Bug Report', 'severity' => 'high']]);

        $this->assertTrue($this->evaluator->evaluate(['metadata.title' => ['eq' => 'Bug Report']], $signal));
        $this->assertTrue($this->evaluator->evaluate(['metadata.severity' => ['eq' => 'high']], $signal));
        $this->assertFalse($this->evaluator->evaluate(['metadata.severity' => ['eq' => 'low']], $signal));
    }

    public function test_dot_notation_deeply_nested(): void
    {
        $signal = $this->makeSignal(['a' => ['b' => ['c' => 'deep']]]);

        $this->assertTrue($this->evaluator->evaluate(['a.b.c' => ['eq' => 'deep']], $signal));
    }

    public function test_unknown_operator_returns_false(): void
    {
        $signal = $this->makeSignal(['value' => 'test']);

        $this->assertFalse($this->evaluator->evaluate(['value' => ['unknown_op' => 'test']], $signal));
    }

    public function test_invalid_field_path_returns_false(): void
    {
        $signal = $this->makeSignal(['value' => 'test']);

        // Field paths with special chars are invalid
        $this->assertFalse($this->evaluator->evaluate(['value$key' => ['eq' => 'test']], $signal));
        $this->assertFalse($this->evaluator->evaluate(['value key' => ['eq' => 'test']], $signal));
    }

    public function test_multiple_conditions_all_must_match(): void
    {
        $signal = $this->makeSignal(['status' => 'active', 'priority' => 5]);

        // Both match
        $this->assertTrue($this->evaluator->evaluate(
            ['status' => ['eq' => 'active'], 'priority' => ['gte' => 3]],
            $signal,
        ));

        // One fails
        $this->assertFalse($this->evaluator->evaluate(
            ['status' => ['eq' => 'active'], 'priority' => ['gte' => 10]],
            $signal,
        ));
    }

    public function test_validate_returns_empty_for_valid_conditions(): void
    {
        $errors = $this->evaluator->validate(['status' => ['eq' => 'active']]);

        $this->assertEmpty($errors);
    }

    public function test_validate_returns_error_for_unknown_operator(): void
    {
        $errors = $this->evaluator->validate(['field' => ['bad_op' => 'value']]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Unknown operator', $errors[0]);
    }

    public function test_validate_returns_error_for_invalid_field_path(): void
    {
        $errors = $this->evaluator->validate(['field$invalid' => ['eq' => 'value']]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Invalid field path', $errors[0]);
    }
}
