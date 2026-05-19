<?php

namespace App\Mcp\Tools\Broadcast;

use App\Domain\Broadcast\Actions\RequestBroadcastApproval;
use App\Domain\Broadcast\Models\Broadcast;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[AssistantTool('write')]
class BroadcastRequestApprovalTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'broadcast_request_approval';

    protected string $description = 'Submit a draft broadcast for approval. Runs a budget check (recipient cap + credit balance) and fails if it cannot be afforded.';

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
            $broadcast = app(RequestBroadcastApproval::class)->execute(
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
