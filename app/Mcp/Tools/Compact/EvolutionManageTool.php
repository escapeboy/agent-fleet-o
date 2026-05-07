<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Evolution\EvolutionAnalyzeTool;
use App\Mcp\Tools\Evolution\EvolutionApplyTool;
use App\Mcp\Tools\Evolution\EvolutionApproveTool;
use App\Mcp\Tools\Evolution\EvolutionProposalListTool;
use App\Mcp\Tools\Evolution\EvolutionRejectTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class EvolutionManageTool extends CompactTool
{
    protected string $name = 'evolution_manage';

    protected string $description = <<<'TXT'
AI-generated improvement proposals — the platform analyzes recent agent runs and suggests prompt tweaks, model swaps, skill additions. Proposals must be reviewed (`analyze`), then either `apply` (mutates the target agent/skill) or `reject`. `apply` is irreversible without a manual rollback through `agent_advanced.rollback`.

Actions:
- list (read) — optional: status (pending/applied/rejected), target_type, limit.
- analyze (read) — proposal_id. Returns LLM-generated rationale, confidence score, diff preview.
- approve (write) — proposal_id. Marks as approved without applying (queue for batch apply).
- apply (DESTRUCTIVE) — proposal_id. Mutates the target entity in place; rollback only via config_history snapshot.
- reject (write) — proposal_id, reason. Closes the proposal.
TXT;

    protected function toolMap(): array
    {
        return [
            'list' => EvolutionProposalListTool::class,
            'analyze' => EvolutionAnalyzeTool::class,
            'approve' => EvolutionApproveTool::class,
            'apply' => EvolutionApplyTool::class,
            'reject' => EvolutionRejectTool::class,
        ];
    }
}
