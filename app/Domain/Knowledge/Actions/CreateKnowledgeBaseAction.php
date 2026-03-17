<?php

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\Models\KnowledgeBase;

class CreateKnowledgeBaseAction
{
    public function execute(
        string $teamId,
        string $name,
        ?string $description = null,
        ?string $agentId = null,
    ): KnowledgeBase {
        return KnowledgeBase::create([
            'team_id' => $teamId,
            'name' => $name,
            'description' => $description,
            'agent_id' => $agentId,
        ]);
    }
}
