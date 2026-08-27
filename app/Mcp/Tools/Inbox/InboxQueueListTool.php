<?php

namespace App\Mcp\Tools\Inbox;

use App\Domain\Inbox\Models\InboxQueue;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class InboxQueueListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'inbox_queue_list';

    protected string $description = 'Saved inbox queues — named filters over the triage inbox. Pass a queue id to inbox_list to apply it.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $queues = InboxQueue::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->orderBy('sort_order')
            ->get();

        return Response::text(json_encode([
            'count' => $queues->count(),
            'queues' => $queues->map(fn (InboxQueue $q): array => [
                'id' => $q->id,
                'name' => $q->name,
                'slug' => $q->slug,
                'kinds' => $q->allowedKinds(),
                'min_risk_score' => $q->minRiskScore(),
                'sort_order' => $q->sort_order,
            ])->toArray(),
        ]));
    }
}
