<?php

namespace App\Mcp\Tools\VoiceSession;

use App\Domain\VoiceSession\Actions\EndVoiceSessionAction;
use App\Domain\VoiceSession\Exceptions\VoiceSessionException;
use App\Domain\VoiceSession\Models\VoiceSession;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class VoiceSessionEndTool extends Tool
{
    protected string $name = 'voice_session_end';

    protected string $description = 'End an active or pending voice session. Sets status to ended and records ended_at. The Python voice worker will gracefully disconnect from the LiveKit room.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'session_id' => $schema->string()
                ->description('UUID of the voice session to end'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $session = VoiceSession::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $request->get('session_id'))
            ->first();

        if (! $session) {
            return Response::text(json_encode(['error' => 'Session not found.']));
        }

        try {
            app(EndVoiceSessionAction::class)->execute($session);
        } catch (VoiceSessionException $e) {
            return Response::text(json_encode(['error' => $e->getMessage()]));
        }

        return Response::text(json_encode([
            'session_id' => $session->id,
            'status' => 'ended',
            'ended_at' => $session->fresh()?->ended_at?->toIso8601String(),
        ]));
    }
}
