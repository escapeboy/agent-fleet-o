<?php

namespace App\Domain\Crew\Services;

use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Models\CrewExecution;
use Closure;

final class CrewExecutionScope
{
    /** @var list<Closure> */
    private array $disposeCallbacks = [];

    public function __construct(private readonly CrewExecution $execution) {}

    public function isCancelled(): bool
    {
        $this->execution->refresh();

        $status = $this->execution->status;

        return $status === CrewExecutionStatus::Failed
            || $status === CrewExecutionStatus::Terminated;
    }

    public function assertNotCancelled(): void
    {
        if ($this->isCancelled()) {
            throw new CrewExecutionCancelledException(
                "Crew execution {$this->execution->id} was cancelled.",
            );
        }
    }

    public function onDispose(Closure $callback): void
    {
        $this->disposeCallbacks[] = $callback;
    }

    public function dispose(): void
    {
        foreach (array_reverse($this->disposeCallbacks) as $callback) {
            try {
                $callback();
            } catch (\Throwable) {
                // Cleanup must not throw
            }
        }
        $this->disposeCallbacks = [];
    }
}
