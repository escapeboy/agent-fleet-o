<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Actions;

use App\Domain\AgentChatProtocol\Enums\AdapterKind;
use App\Domain\AgentChatProtocol\Enums\ExternalAgentStatus;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\AgentChatProtocol\Services\AgentverseClient;
use Illuminate\Support\Str;

/**
 * Install an Agentverse-hosted agent into this team as a callable ExternalAgent.
 *
 * The team must have an Agentverse credential configured (Credential with
 * metadata.provider = 'agentverse' + secret_data.api_key).
 */
class InstallFromAgentverseAction
{
    public function execute(
        string $teamId,
        string $agentAddress,
        bool $useProxy = false,
    ): ExternalAgent {
        $client = AgentverseClient::forTeam($teamId);
        if ($client === null) {
            throw new \RuntimeException('Team has no active Agentverse credential configured');
        }

        $details = $client->getAgent($agentAddress);

        $name = (string) ($details['name'] ?? $agentAddress);
        $handle = (string) ($details['handle'] ?? '');
        $description = (string) ($details['readme'] ?? $details['description'] ?? '');

        // Prevent duplicate installs for the same address on the same team.
        $existing = ExternalAgent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('agent_address', $agentAddress)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return ExternalAgent::create([
            'id' => Str::uuid7()->toString(),
            'team_id' => $teamId,
            'name' => $name,
            'slug' => Str::slug($handle ?: $name).'-'.substr(Str::uuid7()->toString(), 0, 6),
            'agent_address' => $agentAddress,
            'adapter_kind' => ($useProxy ? AdapterKind::AgentverseProxy : AdapterKind::AgentverseMailbox)->value,
            'description' => $description,
            'endpoint_url' => AgentverseClient::BASE_URL.'/agents/'.urlencode($agentAddress),
            'manifest_cached' => $details,
            'manifest_fetched_at' => now(),
            'status' => ExternalAgentStatus::Active,
            'protocol_version' => (string) ($details['protocols'][0] ?? 'asi1-v1'),
            'capabilities' => [
                'supported_message_types' => $details['supported_message_types']
                    ?? ['chat_message', 'chat_acknowledgement'],
                'source' => 'agentverse',
                'handle' => $handle,
                'ranking_score' => $details['ranking_score'] ?? null,
            ],
        ]);
    }
}
