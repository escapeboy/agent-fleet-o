<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Memory\Models\Memory;
use Illuminate\Support\Facades\Log;

/**
 * Marks a Proposed-tier memory as rejected.
 *
 * Keeps the row for audit but stamps proposal_status='rejected' so retrieval
 * paths can filter it out and the proposals list can hide it.
 *
 * Idempotent: a memory already at status approved/rejected is left untouched
 * and the existing decision is returned in the result payload.
 */
class RejectMemoryProposalAction
{
    /**
     * @return array{rejected: bool, already: ?string}
     */
    public function execute(Memory $memory, string $reason, ?string $reviewedBy = null): array
    {
        $existing = $memory->proposal_status;

        if (in_array($existing, ['approved', 'rejected'], true)) {
            Log::info('RejectMemoryProposalAction: already decided, skipping', [
                'memory_id' => $memory->id,
                'status' => $existing,
            ]);

            return ['rejected' => false, 'already' => $existing];
        }

        $memory->update([
            'proposal_status' => 'rejected',
            'reviewed_at' => now(),
            'rejection_reason' => mb_substr(trim($reason), 0, 1000),
            'reviewed_by' => $reviewedBy ?? 'system:reviewer',
        ]);

        return ['rejected' => true, 'already' => null];
    }
}
