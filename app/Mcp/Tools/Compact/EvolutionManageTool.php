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

    protected string $description = 'Manage evolution proposals (AI-suggested improvements). Actions: list (status filter), analyze (proposal_id — detailed analysis), approve (proposal_id), apply (proposal_id — execute the improvement), reject (proposal_id, reason).';

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
