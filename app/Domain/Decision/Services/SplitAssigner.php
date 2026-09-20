<?php

namespace App\Domain\Decision\Services;

/**
 * 20% dev / 80% test, decided by the case id alone. Deterministic on purpose:
 * re-exporting a dataset, or adding cases to it, must not reshuffle which cases
 * were held out, or every number measured before the change becomes unreadable.
 */
final class SplitAssigner
{
    public const DEV = 'dev';

    public const TEST = 'test';

    public const DEV_PERCENT = 20;

    public static function for(string $id): string
    {
        $bucket = hexdec(substr(hash('sha256', $id), 0, 8)) % 100;

        return $bucket < self::DEV_PERCENT ? self::DEV : self::TEST;
    }
}
