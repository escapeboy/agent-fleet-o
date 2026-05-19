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
class BroadcastGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'broadcast_get';

    protected string $description = 'Get one broadcast with its per-recipient delivery breakdown (sent / failed / bounced / pending).';

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

        $breakdown = $broadcast->recipients()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Response::text(json_encode([
            'id' => $broadcast->id,
            'name' => $broadcast->name,
            'audience_id' => $broadcast->audience_id,
            'subject' => $broadcast->subject,
            'status' => $broadcast->status->value,
            'recipient_count' => $broadcast->recipient_count,
            'recipients' => [
                'pending' => (int) ($breakdown['pending'] ?? 0),
                'sent' => (int) ($breakdown['sent'] ?? 0),
                'failed' => (int) ($breakdown['failed'] ?? 0),
                'bounced' => (int) ($breakdown['bounced'] ?? 0),
            ],
            'approved_at' => $broadcast->approved_at?->toIso8601String(),
            'sent_at' => $broadcast->sent_at?->toIso8601String(),
        ]));
    }
}
