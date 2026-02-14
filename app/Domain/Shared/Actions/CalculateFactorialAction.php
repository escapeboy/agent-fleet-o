<?php

namespace App\Domain\Shared\Actions;

use InvalidArgumentException;

class CalculateFactorialAction
{
    private const MAX_INPUT = 170; // 171! exceeds PHP float max

    public function execute(int $number): int|float
    {
        if ($number < 0) {
            throw new InvalidArgumentException('Factorial is not defined for negative numbers.');
        }

        if ($number > self::MAX_INPUT) {
            throw new InvalidArgumentException(
                'Input must not exceed '.self::MAX_INPUT.'. Values above this overflow PHP float precision.',
            );
        }

        $result = 1;

        for ($i = 2; $i <= $number; $i++) {
            $result *= $i;
        }

        return $result;
    }
}
