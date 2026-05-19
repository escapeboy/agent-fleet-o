<?php

namespace App\Mcp\Tools\Broadcast;

use App\Domain\Broadcast\Actions\ApproveBroadcast;
use App\Domain\Broadcast\Models\Broadcast;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[AssistantTool('destructive')]
class BroadcastApproveTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'broadcast_approve';

    protected string $description = 'Approve a broadcast pending approval. Materializes a recipient for every subscribed audience member and dispatches delivery.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'broadcast_id' => $schema->string()
                ->description('The broadcast UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['broadcast_id' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->failedPreconditionError('No team context available.');
        }

        $broadcast = Broadcast::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['broadcast_id']);

        if (! $broadcast) {
            return $this->notFoundError('broadcast', $validated['broadcast_id']);
        }

        try {
            $broadcast = app(ApproveBroadcast::class)->execute(
                $broadcast,
                (string) (auth()->id() ?? 'mcp'),
            );
        } catch (\Throwable $e) {
            return $this->failedPreconditionError($e->getMessage());
        }

        return Response::text(json_encode([
            'id' => $broadcast->id,
            'status' => $broadcast->status->value,
            'recipient_count' => $broadcast->recipient_count,
        ]));
    }
}
