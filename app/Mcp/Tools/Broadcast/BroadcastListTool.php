<?php

namespace App\Mcp\Tools\Broadcast;

use App\Domain\Broadcast\Models\Broadcast;
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
class BroadcastListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'broadcast_list';

    protected string $description = 'List the team\'s broadcasts (mass emails to audiences) with status and recipient counts.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'audience_id' => $schema->string()
                ->description('Filter broadcasts by audience UUID'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->failedPreconditionError('No team context available.');
        }

        $broadcasts = Broadcast::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->when($request->get('audience_id'), fn ($q, $id) => $q->where('audience_id', $id))
            ->latest()
            ->limit(50)
            ->get();

        return Response::text(json_encode([
            'count' => $broadcasts->count(),
            'broadcasts' => $broadcasts->map(fn (Broadcast $b): array => [
                'id' => $b->id,
                'name' => $b->name,
                'audience_id' => $b->audience_id,
                'subject' => $b->subject,
                'status' => $b->status->value,
                'recipient_count' => $b->recipient_count,
                'sent_at' => $b->sent_at?->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
