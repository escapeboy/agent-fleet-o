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
 * Agentverse public search + mailbox endpoints require no authentication, so a
 * team can install & call external agents without configuring a credential.
 * A credential (ASI:One API key) is only needed for authenticated endpoints
 * (user's own agents, future mailbox polling).
 *
 * Field mapping verified against live Agentverse response shape 2026-04-24:
 *   - `rating` (float 0-5) — we store as capabilities.rating (was misnamed ranking_score)
 *   - `handle` may be null
 *   - `avatar_href`, `category`, `featured`, `total_interactions` captured
 */
class InstallFromAgentverseAction
{
    public function execute(
        string $teamId,
        string $agentAddress,
        bool $useProxy = false,
    ): ExternalAgent {
        // Idempotent: return existing installation for the same address.
        $existing = ExternalAgent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('agent_address', $agentAddress)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $client = AgentverseClient::forTeam($teamId);

        // Agentverse has no public "get by address" endpoint — we use the search
        // endpoint with the exact address as search_text to retrieve metadata.
        $results = $client->listAgents([
            'search_text' => $agentAddress,
            'limit' => 5,
        ]);

        $details = null;
        foreach ($results as $candidate) {
            if (($candidate['address'] ?? null) === $agentAddress) {
                $details = $candidate;
                break;
            }
        }

        if ($details === null) {
            throw new \RuntimeException("Agentverse agent {$agentAddress} not found in public search.");
        }

        $name = (string) ($details['name'] ?? $agentAddress);
        $handle = $details['handle'] !== null ? (string) $details['handle'] : null;
        $readme = (string) ($details['readme'] ?? '');
        $description = (string) ($details['description'] ?? '');

        $slugSource = $handle !== null && $handle !== '' ? $handle : $name;
        $slug = Str::slug($slugSource).'-'.substr(Str::uuid7()->toString(), 0, 6);

        return ExternalAgent::create([
            'id' => Str::uuid7()->toString(),
            'team_id' => $teamId,
            'name' => $name,
            'slug' => $slug,
            'agent_address' => $agentAddress,
            'adapter_kind' => ($useProxy ? AdapterKind::AgentverseProxy : AdapterKind::AgentverseMailbox)->value,
            'description' => $description !== '' ? $description : Str::limit(strip_tags($readme), 500),
            'endpoint_url' => AgentverseClient::BASE_URL.'/agents/'.urlencode($agentAddress),
            'manifest_cached' => $details,
            'manifest_fetched_at' => now(),
            'status' => ExternalAgentStatus::Active,
            'protocol_version' => (string) ($details['protocols'][0] ?? 'asi1-v1'),
            'capabilities' => [
                'supported_message_types' => ['chat_message', 'chat_acknowledgement'],
                'source' => 'agentverse',
                'handle' => $handle,
                'rating' => isset($details['rating']) ? (float) $details['rating'] : null,
                'category' => $details['category'] ?? null,
                'featured' => (bool) ($details['featured'] ?? false),
                'avatar_href' => $details['avatar_href'] ?? null,
                'total_interactions' => (int) ($details['total_interactions'] ?? 0),
                'domain' => $details['domain'] ?? null,
                'agent_type' => $details['type'] ?? null,
            ],
        ]);
    }
}
