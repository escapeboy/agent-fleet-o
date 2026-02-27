<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Project\Enums\ProjectExecutionMode;
use App\Domain\Project\Models\Project;
use App\Domain\Tool\Enums\ToolRiskLevel;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\ToolTranslator;
use App\Livewire\Settings\SecurityPolicyPanel;

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

        // Filter by execution mode: watcher projects only get safe/read tools
        if ($project && $project->execution_mode === ProjectExecutionMode::Watcher) {
            $agentTools = $agentTools->filter(
                fn (Tool $tool) => $tool->risk_level === null
                    || $tool->risk_level === ToolRiskLevel::Safe
                    || $tool->risk_level === ToolRiskLevel::Read,
            );
        }

        // Read org-level command security policy from GlobalSettings
        $orgPolicy = SecurityPolicyPanel::getOrgPolicy() ?: null;

        $prismTools = [];
        foreach ($agentTools as $tool) {
            $overrides = $tool->pivot->overrides ?? [];
            $prismTools = array_merge($prismTools, $this->translator->toPrismTools($tool, $overrides, $orgPolicy));
        }

        return $prismTools;
    }
}
