<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\Services\ArgumentPredicateEvaluator;
use PHPUnit\Framework\TestCase;

class ArgumentPredicateEvaluatorTest extends TestCase
{
    private ArgumentPredicateEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new ArgumentPredicateEvaluator;
    }

    public function test_returns_null_when_no_predicates(): void
    {
        $this->assertNull($this->evaluator->evaluate([], ['sql' => 'select 1']));
    }

    public function test_returns_null_when_arg_absent(): void
    {
        $predicates = [['arg' => 'rows', 'op' => 'gt', 'value' => 10]];
        $this->assertNull($this->evaluator->evaluate($predicates, ['sql' => 'x']));
    }

    public function test_numeric_gt_matches(): void
    {
        $predicates = [['arg' => 'scan_gb', 'op' => 'gt', 'value' => 50, 'reason' => 'Large scan']];

        $match = $this->evaluator->evaluate($predicates, ['scan_gb' => 75]);
        $this->assertNotNull($match);
        $this->assertSame('block', $match['action']);
        $this->assertSame('Large scan', $match['reason']);

        $this->assertNull($this->evaluator->evaluate($predicates, ['scan_gb' => 25]));
    }

    public function test_all_numeric_operators(): void
    {
        $this->assertNotNull($this->evaluator->evaluate([['arg' => 'n', 'op' => 'gte', 'value' => 5]], ['n' => 5]));
        $this->assertNotNull($this->evaluator->evaluate([['arg' => 'n', 'op' => 'lt', 'value' => 5]], ['n' => 4]));
        $this->assertNotNull($this->evaluator->evaluate([['arg' => 'n', 'op' => 'lte', 'value' => 5]], ['n' => 5]));
        $this->assertNull($this->evaluator->evaluate([['arg' => 'n', 'op' => 'gt', 'value' => 5]], ['n' => 5]));
    }

    public function test_non_numeric_value_never_matches_numeric_op(): void
    {
        $this->assertNull($this->evaluator->evaluate([['arg' => 'x', 'op' => 'gt', 'value' => 5]], ['x' => 'abc']));
    }

    public function test_eq_and_neq(): void
    {
        $this->assertNotNull($this->evaluator->evaluate([['arg' => 'mode', 'op' => 'eq', 'value' => 'prod']], ['mode' => 'prod']));
        $this->assertNotNull($this->evaluator->evaluate([['arg' => 'mode', 'op' => 'neq', 'value' => 'prod']], ['mode' => 'dev']));
        // Numeric equality compares numerically.
        $this->assertNotNull($this->evaluator->evaluate([['arg' => 'n', 'op' => 'eq', 'value' => 5]], ['n' => '5']));
    }

    public function test_contains_and_matches(): void
    {
        $this->assertNotNull($this->evaluator->evaluate([['arg' => 'cmd', 'op' => 'contains', 'value' => 'rm -rf']], ['cmd' => 'sudo rm -rf /']));
        $this->assertNotNull($this->evaluator->evaluate([['arg' => 'cmd', 'op' => 'matches', 'value' => '/DROP\s+TABLE/i']], ['cmd' => 'drop table users']));
        $this->assertNull($this->evaluator->evaluate([['arg' => 'cmd', 'op' => 'matches', 'value' => '/^safe$/']], ['cmd' => 'not safe']));
    }

    public function test_invalid_regex_does_not_throw_and_does_not_match(): void
    {
        $this->assertNull($this->evaluator->evaluate([['arg' => 'cmd', 'op' => 'matches', 'value' => '/[unterminated']], ['cmd' => 'anything']));
    }

    public function test_length_transform_on_string_and_array(): void
    {
        $this->assertNotNull($this->evaluator->evaluate([['arg' => 'q', 'op' => 'gt', 'value' => 3, 'transform' => 'length']], ['q' => 'hello']));
        $this->assertNotNull($this->evaluator->evaluate([['arg' => 'items', 'op' => 'gte', 'value' => 2, 'transform' => 'length']], ['items' => ['a', 'b']]));
    }

    public function test_case_transforms(): void
    {
        $this->assertNotNull($this->evaluator->evaluate([['arg' => 'env', 'op' => 'eq', 'value' => 'prod', 'transform' => 'lower']], ['env' => 'PROD']));
        $this->assertNotNull($this->evaluator->evaluate([['arg' => 'env', 'op' => 'eq', 'value' => 'PROD', 'transform' => 'upper']], ['env' => 'prod']));
    }

    public function test_unknown_transform_is_identity(): void
    {
        // Unknown transform leaves the value untouched; numeric compare still works.
        $this->assertNotNull($this->evaluator->evaluate([['arg' => 'n', 'op' => 'gt', 'value' => 1, 'transform' => 'bogus']], ['n' => 5]));
    }

    public function test_require_approval_action_preserved(): void
    {
        $match = $this->evaluator->evaluate(
            [['arg' => 'amount', 'op' => 'gt', 'value' => 1000, 'action' => 'require_approval']],
            ['amount' => 5000],
        );
        $this->assertNotNull($match);
        $this->assertSame('require_approval', $match['action']);
    }

    public function test_invalid_action_defaults_to_block(): void
    {
        $match = $this->evaluator->evaluate(
            [['arg' => 'n', 'op' => 'gt', 'value' => 1, 'action' => 'nonsense']],
            ['n' => 5],
        );
        $this->assertNotNull($match);
        $this->assertSame('block', $match['action']);
    }

    public function test_first_matching_predicate_wins(): void
    {
        $predicates = [
            ['arg' => 'n', 'op' => 'gt', 'value' => 100, 'reason' => 'first'],
            ['arg' => 'n', 'op' => 'gt', 'value' => 1, 'reason' => 'second'],
        ];
        $match = $this->evaluator->evaluate($predicates, ['n' => 50]);
        $this->assertNotNull($match);
        $this->assertSame('second', $match['reason']);
    }
}
