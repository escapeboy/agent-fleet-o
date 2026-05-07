<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Agent\AgentCreateTool;
use App\Mcp\Tools\Agent\AgentDeleteTool;
use App\Mcp\Tools\Agent\AgentGetTool;
use App\Mcp\Tools\Agent\AgentListTool;
use App\Mcp\Tools\Agent\AgentTemplatesListTool;
use App\Mcp\Tools\Agent\AgentToggleStatusTool;
use App\Mcp\Tools\Agent\AgentUpdateTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class AgentManageTool extends CompactTool
{
    protected string $name = 'agent_manage';

    protected string $description = <<<'TXT'
Core CRUD for AI agents in the caller's team. For runtime inspection, rollback, skill/tool wiring or feedback use `agent_advanced`. `provider` and `model` are validated against team-configured BYOK and local-LLM credentials at create/update time.

Actions:
- list (read) — optional: status, limit (default 50), cursor.
- get (read) — agent_id.
- create (write) — name, role, goal; optional: backstory, provider, model, skill_ids[], tool_ids[].
- update (write) — agent_id + any creatable field. Partial updates allowed.
- delete (DESTRUCTIVE) — agent_id, confirm=true. Soft-deletes; recoverable for 30 days.
- toggle_status (write) — agent_id. Flips active ↔ disabled.
- templates (read) — pre-built agent templates from the platform catalog.
TXT;

    protected function toolMap(): array
    {
        return [
            'list' => AgentListTool::class,
            'get' => AgentGetTool::class,
            'create' => AgentCreateTool::class,
            'update' => AgentUpdateTool::class,
            'delete' => AgentDeleteTool::class,
            'toggle_status' => AgentToggleStatusTool::class,
            'templates' => AgentTemplatesListTool::class,
        ];
    }
}
