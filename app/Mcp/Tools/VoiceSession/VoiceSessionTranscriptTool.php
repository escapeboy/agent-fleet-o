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
class VoiceSessionTranscriptTool extends Tool
{
    protected string $name = 'voice_session_get_transcript';

    protected string $description = 'Retrieve the full transcript of a voice session. Each turn contains role (user/agent/system), content, and timestamp.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'session_id' => $schema->string()
                ->description('UUID of the voice session'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $session = VoiceSession::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $request->get('session_id'))
            ->with('agent:id,name')
            ->first();

        if (! $session) {
            return Response::text(json_encode(['error' => 'Session not found.']));
        }

        return Response::text(json_encode([
            'session_id' => $session->id,
            'agent_id' => $session->agent_id,
            'agent_name' => $session->agent?->name,
            'status' => $session->status->value,
            'turn_count' => count($session->transcript ?? []),
            'started_at' => $session->started_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'transcript' => $session->transcript ?? [],
        ]));
    }
}
