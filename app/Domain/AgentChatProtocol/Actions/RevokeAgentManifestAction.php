<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Services\AgentManifestCache;

class RevokeAgentManifestAction
{
    public function __construct(private readonly AgentManifestCache $cache) {}

    public function execute(Agent $agent): Agent
    {
        $agent->forceFill([
            'chat_protocol_enabled' => false,
            'chat_protocol_visibility' => 'private',
        ])->save();

        $this->cache->forget($agent);

        return $agent->refresh();
    }
}
