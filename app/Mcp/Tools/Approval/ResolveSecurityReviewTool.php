<?php

namespace App\Mcp\Tools\Approval;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Signal\Jobs\EvaluateContactRiskJob;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ResolveSecurityReviewTool extends Tool
{
    protected string $name = 'approval_security_review_resolve';

    protected string $description = 'Approve or reject a security review request for a high-risk contact. Approving re-evaluates the contact risk score.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'review_id' => $schema->string()
                ->description('The security review approval request UUID')
                ->required(),
            'decision' => $schema->string()
                ->description('Resolution decision: "approve" or "reject"')
                ->enum(['approve', 'reject'])
                ->required(),
            'notes' => $schema->string()
                ->description('Optional reviewer notes'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        if (! $teamId) {
            return Response::error('No current team.');
        }

        $review = ApprovalRequest::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('context->type', 'security_review')
            ->find($request->get('review_id'));

        if (! $review) {
            return Response::error('Security review not found.');
        }

        if ($review->status !== ApprovalStatus::Pending) {
            return Response::error('Security review is already resolved.');
        }

        $decision = $request->get('decision');
        $notes = $request->get('notes');

        if ($decision === 'approve') {
            // ApproveAction handles experiment-linked reviews; security reviews have no experiment,
            // so we update the status directly and re-evaluate.
            $review->update([
                'status' => ApprovalStatus::Approved,
                'reviewed_by' => Auth::id(),
                'reviewer_notes' => $notes,
                'reviewed_at' => now(),
            ]);

            // Verify the referenced contact still belongs to this team before re-evaluating.
            $entityId = $review->context['entity_id'] ?? null;
            $contactExists = $entityId && ContactIdentity::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('id', $entityId)
                ->exists();

            if ($contactExists) {
                EvaluateContactRiskJob::dispatch($entityId);
            }
        } else {
            $review->update([
                'status' => ApprovalStatus::Rejected,
                'reviewed_by' => Auth::id(),
                'rejection_reason' => $notes ?? 'Rejected via MCP',
                'reviewer_notes' => $notes,
                'reviewed_at' => now(),
            ]);
        }

        return Response::text(json_encode([
            'review_id' => $review->id,
            'decision' => $decision,
            'entity_id' => $review->context['entity_id'] ?? null,
        ]));
    }
}
