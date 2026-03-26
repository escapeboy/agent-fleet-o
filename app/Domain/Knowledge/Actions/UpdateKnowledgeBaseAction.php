<?php

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\Models\KnowledgeBase;

class UpdateKnowledgeBaseAction
{
    public function execute(
        KnowledgeBase $knowledgeBase,
        ?string $name = null,
        bool $updateDescription = false,
        ?string $description = null,
        bool $updateAgentId = false,
        ?string $agentId = null,
    ): KnowledgeBase {
        if ($name !== null) {
            $knowledgeBase->name = $name;
        }

        if ($updateDescription) {
            $knowledgeBase->description = $description;
        }

        if ($updateAgentId) {
            $knowledgeBase->agent_id = $agentId;
        }

        $knowledgeBase->save();

        activity()->performedOn($knowledgeBase)->log('knowledge_base.updated');

        return $knowledgeBase->fresh();
    }
}
