<?php

namespace App\Infrastructure\AI\Exceptions;

use RuntimeException;

class DataClassificationException extends RuntimeException
{
    public function __construct(string $agentId, string $classification)
    {
        parent::__construct(
            "Agent [{$agentId}] requires local-only execution (classification: {$classification}) "
            .'but no local provider is configured. Add a bridge connection or lower the data_classification.',
        );
    }
}
