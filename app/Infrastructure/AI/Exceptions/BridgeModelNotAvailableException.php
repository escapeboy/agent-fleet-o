<?php

namespace App\Infrastructure\AI\Exceptions;

use RuntimeException;

class BridgeModelNotAvailableException extends RuntimeException
{
    /**
     * @param  list<string>  $available
     */
    public function __construct(
        public readonly string $model,
        public readonly array $available,
    ) {
        parent::__construct(sprintf(
            'Model "%s" is not served by any online FleetQ Bridge LLM endpoint. Available models: %s. '
            .'Choose one of these in the agent or skill model settings.',
            $model,
            $available === [] ? '(none)' : implode(', ', $available),
        ));
    }
}
