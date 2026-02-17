<?php

namespace Tests\Unit\Domain\Testing;

use App\Domain\Testing\Actions\EvaluateOutputAction;
use App\Domain\Testing\Enums\TestStatus;
use App\Domain\Testing\Enums\TestStrategy;
use App\Domain\Testing\Models\TestRun;
use App\Domain\Testing\Models\TestSuite;
use PHPUnit\Framework\TestCase;

class RegressionTestsTest extends TestCase
{
    public function test_test_strategy_enum_has_expected_cases(): void
    {
        $this->assertCount(4, TestStrategy::cases());
        $this->assertEquals('full', TestStrategy::Full->value);
        $this->assertEquals('lint_only', TestStrategy::LintOnly->value);
        $this->assertEquals('smoke', TestStrategy::Smoke->value);
        $this->assertEquals('regression', TestStrategy::Regression->value);
    }

    public function test_test_status_enum_has_expected_cases(): void
    {
        $this->assertCount(5, TestStatus::cases());
        $this->assertTrue(TestStatus::Passed->isTerminal());
        $this->assertTrue(TestStatus::Failed->isTerminal());
        $this->assertTrue(TestStatus::Skipped->isTerminal());
        $this->assertFalse(TestStatus::Running->isTerminal());
        $this->assertFalse(TestStatus::Pending->isTerminal());
    }

    public function test_evaluate_output_passes_with_no_rules(): void
    {
        $action = new EvaluateOutputAction;

        $result = $action->execute(
            output: [
                'experiment_id' => 'test-123',
                'stages' => [
                    ['stage' => 'planning', 'status' => 'completed', 'output_snapshot' => null],
                    ['stage' => 'building', 'status' => 'completed', 'output_snapshot' => null],
                ],
            ],
            assertionRules: [],
            qualityThreshold: 0.5,
        );

        $this->assertTrue($result['passed']);
        $this->assertGreaterThanOrEqual(0.5, $result['score']);
    }

    public function test_evaluate_output_fails_with_all_failed_stages(): void
    {
        $action = new EvaluateOutputAction;

        $result = $action->execute(
            output: [
                'experiment_id' => 'test-456',
                'stages' => [
                    ['stage' => 'planning', 'status' => 'planning_failed', 'output_snapshot' => null],
                ],
            ],
            assertionRules: [],
            qualityThreshold: 0.8,
        );

        $this->assertFalse($result['passed']);
    }

    public function test_evaluate_output_contains_rule_passes(): void
    {
        $action = new EvaluateOutputAction;

        $result = $action->execute(
            output: [
                'experiment_id' => 'test-789',
                'stages' => [
                    ['stage' => 'building', 'status' => 'completed', 'output_snapshot' => ['result' => 'Success: code compiled']],
                ],
            ],
            assertionRules: [
                ['type' => 'contains', 'target' => 'Success', 'field' => 'stages'],
            ],
            qualityThreshold: 0.5,
        );

        $this->assertTrue($result['passed']);
        $this->assertEquals(1.0, $result['score']);
    }

    public function test_evaluate_output_not_contains_rule(): void
    {
        $action = new EvaluateOutputAction;

        $result = $action->execute(
            output: [
                'experiment_id' => 'test-abc',
                'stages' => [
                    ['stage' => 'building', 'status' => 'completed', 'output_snapshot' => ['result' => 'All good']],
                ],
            ],
            assertionRules: [
                ['type' => 'not_contains', 'target' => 'Error', 'field' => 'stages'],
            ],
            qualityThreshold: 0.5,
        );

        $this->assertTrue($result['passed']);
    }

    public function test_evaluate_output_min_stages_rule(): void
    {
        $action = new EvaluateOutputAction;

        $result = $action->execute(
            output: [
                'experiment_id' => 'test-def',
                'stages' => [
                    ['stage' => 'planning', 'status' => 'completed', 'output_snapshot' => null],
                ],
            ],
            assertionRules: [
                ['type' => 'min_stages', 'target' => '3'],
            ],
            qualityThreshold: 0.5,
        );

        $this->assertFalse($result['passed']);
        $this->assertEquals(0.0, $result['score']);
    }

    public function test_test_suite_model_fillable(): void
    {
        $suite = new TestSuite;

        $this->assertContains('name', $suite->getFillable());
        $this->assertContains('test_strategy', $suite->getFillable());
        $this->assertContains('assertion_rules', $suite->getFillable());
        $this->assertContains('quality_threshold', $suite->getFillable());
        $this->assertContains('test_agent_count', $suite->getFillable());
    }

    public function test_test_run_model_fillable(): void
    {
        $run = new TestRun;

        $this->assertContains('test_suite_id', $run->getFillable());
        $this->assertContains('experiment_id', $run->getFillable());
        $this->assertContains('status', $run->getFillable());
        $this->assertContains('results', $run->getFillable());
        $this->assertContains('score', $run->getFillable());
    }

    public function test_test_strategy_labels(): void
    {
        $this->assertEquals('Full Test Suite', TestStrategy::Full->label());
        $this->assertEquals('Lint Only', TestStrategy::LintOnly->label());
        $this->assertEquals('Smoke Tests', TestStrategy::Smoke->label());
        $this->assertEquals('Regression Tests', TestStrategy::Regression->label());
    }
}
