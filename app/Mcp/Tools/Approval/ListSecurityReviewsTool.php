<?php

namespace App\Mcp\Tools\Approval;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListSecurityReviewsTool extends Tool
{
    protected string $name = 'approval_security_reviews_list';

    protected string $description = 'List pending security review approval requests for high-risk contacts.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'include_resolved' => $schema->boolean()
                ->description('Include approved/rejected reviews (default: false)')
                ->default(false),
            'limit' => $schema->integer()
                ->description('Maximum number of results (default: 25, max: 100)')
                ->default(25),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = Auth::user()?->current_team_id;

        if (! $teamId) {
            return Response::error('No current team.');
        }

        $includeResolved = (bool) $request->get('include_resolved', false);
        $limit = min((int) $request->get('limit', 25), 100);

        $query = ApprovalRequest::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('context->type', 'security_review')
            ->orderByDesc('created_at')
            ->limit($limit);

        if (! $includeResolved) {
            $query->where('status', ApprovalStatus::Pending->value);
        }

        $reviews = $query->get();

        return Response::text(json_encode([
            'total' => $reviews->count(),
            'reviews' => $reviews->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status->value,
                'entity_type' => $r->context['entity_type'] ?? null,
                'entity_id' => $r->context['entity_id'] ?? null,
                'entity_display_name' => $r->context['entity_display_name'] ?? null,
                'risk_score' => $r->context['risk_score'] ?? null,
                'triggered_rules' => $r->context['triggered_rules'] ?? [],
                'review_threshold' => $r->context['review_threshold'] ?? null,
                'expires_at' => $r->expires_at?->toIso8601String(),
                'created_at' => $r->created_at->toIso8601String(),
            ])->values()->toArray(),
        ]));
    }
}
