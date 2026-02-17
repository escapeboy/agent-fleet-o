<?php

namespace App\Domain\Memory\Services;

use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;

class MemoryContextInjector
{
    public function __construct(
        private readonly RetrieveRelevantMemoriesAction $retrieveMemories,
    ) {}

    /**
     * Build a memory context string for injection into agent system prompts.
     *
     * Returns null if memory is disabled, input is empty, or no relevant memories found.
     */
    public function buildContext(string $agentId, mixed $input, ?string $projectId = null): ?string
    {
        if (! config('memory.enabled', true) || empty($input)) {
            return null;
        }

        $queryText = is_string($input) ? $input : json_encode($input);
        $memories = $this->retrieveMemories->execute(
            agentId: $agentId,
            query: $queryText,
            projectId: $projectId,
        );

        if ($memories->isEmpty()) {
            return null;
        }

        $memoryList = $memories->map(fn ($m) => "- {$m->content}")->implode("\n");

        return "## Relevant Context from Past Executions\n{$memoryList}";
    }
}
