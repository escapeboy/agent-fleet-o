<?php

namespace App\Domain\FeatureFlag\Exceptions;

use RuntimeException;

class UnknownFeatureFlagException extends RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct("Unknown feature flag: {$key}");
    }
}
