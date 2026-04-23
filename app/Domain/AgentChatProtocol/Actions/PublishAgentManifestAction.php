<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Enums\AgentChatVisibility;
use App\Domain\AgentChatProtocol\Services\AgentManifestCache;
use Illuminate\Support\Str;

class PublishAgentManifestAction
{
    public function __construct(private readonly AgentManifestCache $cache) {}

    public function execute(
        Agent $agent,
        AgentChatVisibility $visibility,
        ?array $config = null,
        ?string $customSlug = null,
    ): Agent {
        $slug = $customSlug ?? $agent->chat_protocol_slug ?? Str::slug($agent->name).'-'.substr($agent->id, 0, 8);

        $needsSecret = $visibility->allowsPublicManifest() && empty($agent->chat_protocol_secret);
        $secret = $needsSecret ? Str::random(48) : $agent->chat_protocol_secret;

        $agent->forceFill([
            'chat_protocol_enabled' => true,
            'chat_protocol_visibility' => $visibility->value,
            'chat_protocol_slug' => $slug,
            'chat_protocol_config' => $config ?? $agent->chat_protocol_config,
            'chat_protocol_secret' => $secret,
        ])->save();

        $this->cache->forget($agent);

        return $agent->refresh();
    }
}
