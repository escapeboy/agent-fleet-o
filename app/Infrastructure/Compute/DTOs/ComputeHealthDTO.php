<?php

namespace App\Infrastructure\Compute\DTOs;

readonly class ComputeHealthDTO
{
    public function __construct(
        public bool $healthy,
        public int $workersReady = 0,
        public int $workersRunning = 0,
        public int $jobsInQueue = 0,
        public ?string $message = null,
    ) {}
}
