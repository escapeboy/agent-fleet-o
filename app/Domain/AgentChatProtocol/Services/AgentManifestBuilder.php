<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\DTOs\AgentManifestDTO;
use App\Domain\AgentChatProtocol\Enums\AgentChatVisibility;

class AgentManifestBuilder
{
    public function build(Agent $agent): AgentManifestDTO
    {
        $config = (array) ($agent->chat_protocol_config ?? []);
        $supportedTypes = $config['supported_message_types'] ?? [
            'chat_message',
            'chat_acknowledgement',
            'structured_output_request',
            'structured_output_response',
        ];

        $visibility = $agent->chat_protocol_visibility instanceof AgentChatVisibility
            ? $agent->chat_protocol_visibility
            : AgentChatVisibility::from((string) ($agent->chat_protocol_visibility ?? 'private'));

        $authScheme = $visibility->requiresSanctum() ? 'sanctum-bearer' : 'agent-hmac-jwt';
        $slug = (string) ($agent->chat_protocol_slug ?? $agent->id);
        $endpoint = url('/api/v1/agents/'.$agent->id.'/chat');

        $fleetqExt = [
            'extension' => config('agent_chat.fleetq_extension_uri'),
            'approval_required' => (bool) ($config['approval_required'] ?? false),
            'cost_preview' => (array) ($config['cost_preview'] ?? []),
            'tags' => (array) ($config['tags'] ?? []),
            'slug' => $slug,
        ];

        return new AgentManifestDTO(
            identifier: $slug,
            name: (string) $agent->name,
            description: (string) ($agent->description ?? $agent->goal ?? ''),
            protocolUri: (string) config('agent_chat.protocol_manifest_uri'),
            supportedMessageTypes: array_values((array) $supportedTypes),
            endpoint: $endpoint,
            authScheme: $authScheme,
            capabilities: [
                'streaming' => (bool) ($config['streaming'] ?? true),
                'async' => (bool) ($config['async'] ?? true),
                'max_input_tokens' => (int) ($config['max_input_tokens'] ?? 128_000),
            ],
            fleetqExtension: $fleetqExt,
            version: (string) config('agent_chat.protocol_version', 'asi1-v1'),
        );
    }
}
