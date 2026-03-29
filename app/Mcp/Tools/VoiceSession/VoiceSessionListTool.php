<?php

namespace App\Mcp\Tools\VoiceSession;

use App\Domain\VoiceSession\Models\VoiceSession;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class VoiceSessionListTool extends Tool
{
    protected string $name = 'voice_session_list';

    protected string $description = 'List voice sessions for the team. Returns id, agent_id, room_name, status, started_at, ended_at, and transcript turn count.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: pending, active, ended, failed')
                ->enum(['pending', 'active', 'ended', 'failed']),
            'agent_id' => $schema->string()
                ->description('Filter by agent UUID'),
            'limit' => $schema->integer()
                ->description('Max results (default 10, max 100)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $query = VoiceSession::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->orderByDesc('created_at');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($agentId = $request->get('agent_id')) {
            $query->where('agent_id', $agentId);
        }

        $limit = min((int) ($request->get('limit', 10)), 100);
        $sessions = $query->with('agent:id,name')->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $sessions->count(),
            'sessions' => $sessions->map(fn ($s) => [
                'id' => $s->id,
                'agent_id' => $s->agent_id,
                'agent_name' => $s->agent?->name,
                'room_name' => $s->room_name,
                'status' => $s->status->value,
                'turn_count' => count($s->transcript ?? []),
                'started_at' => $s->started_at?->toIso8601String(),
                'ended_at' => $s->ended_at?->toIso8601String(),
                'created_at' => $s->created_at->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
