<?php

namespace Tests\Unit\Domain\Shared;

use App\Domain\Shared\Actions\CalculateFactorialAction;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CalculateFactorialTest extends TestCase
{
    private CalculateFactorialAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new CalculateFactorialAction;
    }

    #[DataProvider('factorialProvider')]
    public function test_computes_factorial(int $input, int|float $expected): void
    {
        $this->assertSame($expected, $this->action->execute($input));
    }

    public static function factorialProvider(): array
    {
        return [
            'zero' => [0, 1],
            'one' => [1, 1],
            'two' => [2, 2],
            'three' => [3, 6],
            'five' => [5, 120],
            'ten' => [10, 3628800],
            'twenty' => [20, 2432902008176640000],
        ];
    }

    public function test_rejects_negative_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not defined for negative');

        $this->action->execute(-1);
    }

    public function test_rejects_large_negative_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->action->execute(-100);
    }

    public function test_rejects_input_exceeding_max(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not exceed 170');

        $this->action->execute(171);
    }

    public function test_max_boundary_does_not_throw(): void
    {
        $result = $this->action->execute(170);

        $this->assertGreaterThan(0, $result);
    }
}
