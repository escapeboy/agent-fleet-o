<?php

namespace App\Domain\Integration\Events;

use App\Domain\Integration\Models\Integration;
use Illuminate\Foundation\Events\Dispatchable;

class IntegrationActionExecuted
{
    use Dispatchable;

    public string $eventName;

    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public Integration $integration,
        public string $action,
        public array $params,
        public bool $success,
        public ?string $errorMessage = null,
        public ?int $latencyMs = null,
    ) {
        $this->eventName = $success ? 'integration.executed' : 'integration.execute.failed';
    }
}
