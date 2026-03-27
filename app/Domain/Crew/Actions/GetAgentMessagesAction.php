<?php

namespace App\Domain\Crew\Actions;

use App\Domain\Crew\Models\CrewAgentMessage;
use App\Domain\Crew\Models\CrewExecution;
use Illuminate\Support\Collection;

class GetAgentMessagesAction
{
    /**
     * Retrieve inter-agent messages for a crew execution.
     *
     * When $recipientAgentId is null, messages without a specific recipient (broadcasts) are included.
     * When $includebroadcast is true (default), broadcast messages (null recipient) are always included.
     */
    public function execute(
        CrewExecution $execution,
        ?int $round = null,
        ?string $recipientAgentId = null,
        bool $includebroadcast = true,
    ): Collection {
        $query = CrewAgentMessage::where('crew_execution_id', $execution->id);

        if ($round !== null) {
            $query->where('round', $round);
        }

        if ($recipientAgentId !== null) {
            if ($includebroadcast) {
                // Messages addressed to this agent OR broadcasts (null recipient)
                $query->where(function ($q) use ($recipientAgentId) {
                    $q->where('recipient_agent_id', $recipientAgentId)
                        ->orWhereNull('recipient_agent_id');
                });
            } else {
                $query->where('recipient_agent_id', $recipientAgentId);
            }
        }

        return $query->orderBy('round')->orderBy('created_at')->get();
    }
}
