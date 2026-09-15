<?php

namespace App\Domain\Tool\Services;

use Prism\Prism\Tool as PrismToolObject;

/**
 * Late-bound view of an agent's resolved tools for `run_tool_program`.
 *
 * The program tool is translated together with its siblings, so at build
 * time the final tool list does not exist yet. ResolveAgentToolsAction
 * creates one context per resolution, hands it to the translator, and calls
 * bind() once the profile-filtered list is known. Until then every lookup
 * fails closed.
 */
final class ToolProgramContext
{
    public const PROGRAM_TOOL_NAME = 'run_tool_program';

    /** @var array<string, PrismToolObject> */
    private array $tools = [];

    private bool $bound = false;

    private int $callsUsed = 0;

    /**
     * Reserve sub-calls against the per-execution budget. The agent loop's
     * circuit breakers count one step per run_tool_program call, so without
     * this budget 12 steps could fan out into hundreds of host tool runs.
     */
    public function reserveCalls(int $count, int $maxTotal): bool
    {
        if ($this->callsUsed + $count > $maxTotal) {
            return false;
        }

        $this->callsUsed += $count;

        return true;
    }

    public function callsUsed(): int
    {
        return $this->callsUsed;
    }

    /** @var array<string, true> */
    private array $excluded = [];

    /**
     * Keep tools out of the batch path even though the agent can call them
     * directly — e.g. tools whose pivot approval_mode is `ask`. A batch must
     * never be a way around a per-call approval.
     *
     * @param  array<int, string>  $names
     */
    public function exclude(array $names): void
    {
        foreach ($names as $name) {
            $this->excluded[$name] = true;
        }
    }

    /**
     * @param  array<int, PrismToolObject>  $prismTools
     */
    public function bind(array $prismTools): void
    {
        $this->tools = [];
        foreach ($prismTools as $tool) {
            if ($tool->name() === self::PROGRAM_TOOL_NAME) {
                continue; // recursion guard
            }
            if (isset($this->excluded[$tool->name()])) {
                continue;
            }
            $this->tools[$tool->name()] = $tool;
        }
        $this->bound = true;
    }

    public function isBound(): bool
    {
        return $this->bound;
    }

    public function find(string $name): ?PrismToolObject
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }
}
