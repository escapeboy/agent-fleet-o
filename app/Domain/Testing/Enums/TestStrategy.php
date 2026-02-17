<?php

namespace App\Domain\Testing\Enums;

enum TestStrategy: string
{
    case Full = 'full';
    case LintOnly = 'lint_only';
    case Smoke = 'smoke';
    case Regression = 'regression';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full Test Suite',
            self::LintOnly => 'Lint Only',
            self::Smoke => 'Smoke Tests',
            self::Regression => 'Regression Tests',
        };
    }
}
