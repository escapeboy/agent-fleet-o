<?php

namespace App\Mcp\Tools\Inbox;

use App\Domain\Inbox\Models\InboxQueue;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class InboxQueueDeleteTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'inbox_queue_delete';

    protected string $description = 'Delete a saved inbox queue. The items it filtered are untouched — only the saved view is removed.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'queue_id' => $schema->string()
                ->description('The inbox queue UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $queue = InboxQueue::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($request->get('queue_id'));

        if (! $queue) {
            return $this->notFoundError('inbox queue');
        }

        $queue->delete();

        return Response::text(json_encode([
            'success' => true,
            'id' => $request->get('queue_id'),
        ]));
    }
}
