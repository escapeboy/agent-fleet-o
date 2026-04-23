<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Models\AgentChatSession;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use Illuminate\Support\Str;

class SessionManager
{
    public function resolve(
        string $teamId,
        string $sessionToken,
        ?Agent $agent = null,
        ?ExternalAgent $externalAgent = null,
    ): AgentChatSession {
        $session = AgentChatSession::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('session_token', $sessionToken)
            ->first();

        if ($session === null) {
            $session = AgentChatSession::create([
                'id' => Str::uuid7()->toString(),
                'team_id' => $teamId,
                'agent_id' => $agent?->id,
                'external_agent_id' => $externalAgent?->id,
                'session_token' => $sessionToken,
                'last_activity_at' => now(),
                'message_count' => 0,
                'metadata' => [],
            ]);
        }

        return $session;
    }

    public function touch(AgentChatSession $session): void
    {
        $session->forceFill([
            'last_activity_at' => now(),
            'message_count' => (int) $session->message_count + 1,
        ])->save();
    }

    public function generateToken(): string
    {
        return Str::uuid7()->toString();
    }
}
