<?php

namespace App\Mcp\Tools\ProductGraph;

use App\Domain\ProductGraph\Enums\ChangeStatus;
use App\Domain\ProductGraph\Models\ProductGraphChange;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[AssistantTool('read')]
class ProductGraphChangesListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'product_graph_changes_list';

    protected string $description = 'List proposed product-graph changes (the human review queue). Defaults to pending proposals awaiting approval.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: pending | approved | rejected | applied (default pending)'),
            'limit' => $schema->integer()
                ->description('Max changes to return (default 50, max 200)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : null;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $validated = $request->validate([
            'status' => 'nullable|string|in:pending,approved,rejected,applied',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $changes = ProductGraphChange::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('status', $validated['status'] ?? ChangeStatus::Pending->value)
            ->latest()
            ->limit($validated['limit'] ?? 50)
            ->get();

        return Response::text(json_encode([
            'count' => $changes->count(),
            'changes' => $changes->map(fn (ProductGraphChange $c) => [
                'id' => $c->id,
                'change_type' => $c->change_type->value,
                'target_id' => $c->target_id,
                'payload' => $c->payload,
                'status' => $c->status->value,
                'proposed_by' => $c->proposed_by_label,
                'created_at' => $c->created_at?->toIso8601String(),
            ])->values()->toArray(),
        ]));
    }
}
