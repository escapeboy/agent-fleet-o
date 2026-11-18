<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Workflow\Models\Workflow;
use Illuminate\Support\Collection;

/**
 * Returns the workflows a team has exposed as MCP gateway tools.
 */
class ListMcpGatewayWorkflowsAction
{
    /**
     * @return Collection<int, Workflow>
     */
    public function execute(?string $teamId): Collection
    {
        if ($teamId === null || $teamId === '') {
            return new Collection;
        }

        return Workflow::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('mcp_exposed', true)
            ->whereNotNull('mcp_tool_name')
            ->get()
            ->toBase();
    }
}
