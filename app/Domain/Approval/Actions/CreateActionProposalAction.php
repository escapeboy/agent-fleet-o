<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Assistant\Models\AssistantConversation;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class CreateActionProposalAction
{
    /**
     * Create a pending action proposal. Optionally captures the last 3
     * messages from an assistant conversation as lineage so the human
     * approver sees the chain that led to the proposal.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $lineage
     */
    public function execute(
        string $teamId,
        string $targetType,
        ?string $targetId,
        string $summary,
        array $payload,
        array $lineage = [],
        ?string $userId = null,
        ?string $agentId = null,
        string $riskLevel = 'high',
        ?CarbonInterface $expiresAt = null,
        ?AssistantConversation $conversation = null,
    ): ActionProposal {
        if ($conversation && empty($lineage)) {
            $lineage = $this->captureLineage($conversation);
        }

        return ActionProposal::create([
            'team_id' => $teamId,
            'actor_user_id' => $userId,
            'actor_agent_id' => $agentId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'summary' => Str::limit($summary, 250),
            'payload' => $payload,
            'lineage' => $lineage,
            'risk_level' => $riskLevel,
            'status' => 'pending',
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function captureLineage(AssistantConversation $conversation): array
    {
        $messages = $conversation->messages()
            ->latest('created_at')
            ->limit(3)
            ->get(['id', 'role', 'content', 'created_at'])
            ->reverse()
            ->values();

        return $messages->map(fn ($m) => [
            'kind' => 'assistant_message',
            'id' => $m->id,
            'role' => (string) $m->role,
            'snippet' => Str::limit((string) $m->content, 200),
            'at' => optional($m->created_at)->toIso8601String(),
        ])->all();
    }
}
