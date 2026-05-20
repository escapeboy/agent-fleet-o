<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Services;

use App\Domain\Inbox\Models\InboxTriageResult;

/**
 * Records user actions (approve/reject) on triaged items so the LLM
 * triage prompt can include "recent team feedback" in subsequent calls.
 *
 * No-op when no triage result exists for the item.
 */
class TriageFeedbackTracker
{
    public function recordAction(string $teamId, string $sourceKind, string $sourceId, string $action): void
    {
        if (! in_array($action, ['approved', 'rejected'], true)) {
            return;
        }

        InboxTriageResult::where('team_id', $teamId)
            ->where('source_kind', $sourceKind)
            ->where('source_id', $sourceId)
            ->update([
                'user_action' => $action,
                'user_acted_at' => now(),
            ]);
    }
}
