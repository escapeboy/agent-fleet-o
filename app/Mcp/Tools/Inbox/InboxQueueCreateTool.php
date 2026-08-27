<?php

namespace App\Mcp\Tools\Inbox;

use App\Domain\Inbox\Models\InboxQueue;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class InboxQueueCreateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'inbox_queue_create';

    protected string $description = 'Create a saved inbox queue — a named filter over the triage inbox.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Queue name (2-100 chars).')
                ->required(),
            'kinds' => $schema->array()
                ->description('Item kinds this queue shows: approval, human_task, proposal. Empty means all.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => 'required|string|min:2|max:100',
            'kinds' => 'array',
            'kinds.*' => 'in:approval,human_task,proposal',
        ]);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $queue = InboxQueue::create([
            'team_id' => $teamId,
            'user_id' => auth()->id(),
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']).'-'.Str::random(6),
            'filter_rules' => ['kinds' => $validated['kinds'] ?? []],
            'sort_order' => InboxQueue::withoutGlobalScopes()->where('team_id', $teamId)->count(),
        ]);

        return Response::text(json_encode([
            'success' => true,
            'id' => $queue->id,
            'name' => $queue->name,
            'slug' => $queue->slug,
            'kinds' => $queue->allowedKinds(),
        ]));
    }
}
