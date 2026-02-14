<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Project\Models\Project;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\ToolTranslator;

class ResolveAgentToolsAction
{
    public function __construct(
        private readonly ToolTranslator $translator,
    ) {}

    /**
     * Resolve all PrismPHP Tool objects available for an agent execution.
     *
     * @return array<\Prism\Prism\Tool>
     */
    public function execute(Agent $agent, ?Project $project = null): array
    {
        $agentTools = $agent->tools()
            ->where('status', ToolStatus::Active->value)
            ->get();

        // Apply project-level restrictions if set
        if ($project && ! empty($project->allowed_tool_ids)) {
            $agentTools = $agentTools->filter(
                fn (Tool $tool) => in_array($tool->id, $project->allowed_tool_ids),
            );
        }

        $prismTools = [];
        foreach ($agentTools as $tool) {
            $overrides = $tool->pivot->overrides ?? [];
            $prismTools = array_merge($prismTools, $this->translator->toPrismTools($tool, $overrides));
        }

        return $prismTools;
    }
}
