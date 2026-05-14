<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Models\Memory;
use Illuminate\Support\Facades\Log;

/**
 * Heuristic auditor over Proposed-tier memories.
 *
 * For each pending proposal:
 *   - rejects if content too short or confidence too low
 *   - approves (promotes to metadata.target_tier) if confidence above the auto-approve threshold
 *   - leaves pending otherwise — human reviews via MemoryBrowserPage / MCP
 *
 * v1 is heuristic-only. LLM-based novelty / utility scoring is deferred until
 * teams report queue overflow.
 */
class AuditMemoryProposalsAction
{
    public function __construct(
        private readonly RejectMemoryProposalAction $reject,
    ) {}

    /**
     * @param  string|null  $teamId  optional scoping — when null, audits every team.
     * @return array{approved: int, rejected: int, queued: int}
     */
    public function execute(?string $teamId = null, int $limit = 200): array
    {
        $minLength = (int) config('memory.proposal_workflow.min_content_length', 30);
        $minConfidence = (float) config('memory.proposal_workflow.min_confidence', 0.3);
        $autoApprove = (float) config('memory.proposal_workflow.auto_approve_threshold', 0.85);

        $query = Memory::withoutGlobalScopes()
            ->where('tier', MemoryTier::Proposed->value)
            ->whereNull('proposal_status')
            ->orderBy('created_at')
            ->limit($limit);

        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        $proposals = $query->get();

        $approved = 0;
        $rejected = 0;
        $queued = 0;

        foreach ($proposals as $memory) {
            $contentLength = mb_strlen((string) $memory->content);
            $confidence = (float) ($memory->confidence ?? 0.0);

            if ($contentLength < $minLength) {
                $result = $this->reject->execute(
                    $memory,
                    "Content shorter than {$minLength} chars ({$contentLength}).",
                    'system:auditor',
                );
                if ($result['rejected']) {
                    $rejected++;
                }

                continue;
            }

            if ($confidence < $minConfidence) {
                $result = $this->reject->execute(
                    $memory,
                    "Confidence {$confidence} below minimum {$minConfidence}.",
                    'system:auditor',
                );
                if ($result['rejected']) {
                    $rejected++;
                }

                continue;
            }

            if ($confidence >= $autoApprove) {
                $this->autoApprove($memory);
                $approved++;

                continue;
            }

            $queued++;
        }

        if ($approved || $rejected) {
            Log::info('AuditMemoryProposalsAction: completed sweep', [
                'team_id' => $teamId,
                'approved' => $approved,
                'rejected' => $rejected,
                'queued' => $queued,
                'inspected' => $proposals->count(),
            ]);
        }

        return [
            'approved' => $approved,
            'rejected' => $rejected,
            'queued' => $queued,
        ];
    }

    private function autoApprove(Memory $memory): void
    {
        $targetTier = $this->resolveTargetTier($memory);

        $memory->update([
            'tier' => $targetTier->value,
            'proposal_status' => 'approved',
            'reviewed_at' => now(),
            'reviewed_by' => 'system:auditor',
        ]);
    }

    /**
     * Pick the promotion target. The extractor stamps metadata.target_tier;
     * fall back to Canonical if the metadata is missing or invalid.
     */
    private function resolveTargetTier(Memory $memory): MemoryTier
    {
        $candidate = $memory->metadata['target_tier'] ?? null;

        if (is_string($candidate)) {
            $tier = MemoryTier::tryFrom($candidate);
            if ($tier && $tier->isCurated()) {
                return $tier;
            }
        }

        return MemoryTier::Canonical;
    }
}
