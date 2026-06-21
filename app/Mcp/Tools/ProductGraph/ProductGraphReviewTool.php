<?php

namespace App\Mcp\Tools\ProductGraph;

use App\Domain\ProductGraph\Actions\ReviewChangeAction;
use App\Domain\ProductGraph\Models\ProductGraphChange;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use RuntimeException;

/**
 * Approve or reject a pending product-graph proposal. Approval applies the change.
 */
#[IsDestructive]
#[AssistantTool('write')]
class ProductGraphReviewTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'product_graph_review';

    protected string $description = 'Approve or reject a pending product-graph proposal. Approving applies the change to the graph; rejecting discards it. Reserved for human/admin decisions.';

    public function __construct(private readonly ReviewChangeAction $action) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'change_id' => $schema->string()
                ->description('UUID of the pending change to review')
                ->required(),
            'decision' => $schema->string()
                ->description('approve | reject')
                ->required(),
            'note' => $schema->string()
                ->description('Optional review note'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : null;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $validated = $request->validate([
            'change_id' => 'required|string',
            'decision' => 'required|string|in:approve,reject',
            'note' => 'nullable|string|max:255',
        ]);

        $change = ProductGraphChange::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['change_id']);

        if (! $change) {
            return $this->notFoundError('product graph change', $validated['change_id']);
        }

        try {
            $change = $this->action->execute(
                change: $change,
                approve: $validated['decision'] === 'approve',
                reviewerUserId: null,
                note: $validated['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return $this->failedPreconditionError($e->getMessage());
        }

        return Response::text(json_encode([
            'id' => $change->id,
            'status' => $change->status->value,
            'applied_ref_id' => $change->applied_ref_id,
        ]));
    }
}
