<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Actions;

use App\Domain\AgentChatProtocol\DTOs\A2aAgentCard;
use App\Domain\AgentChatProtocol\Enums\AdapterKind;
use App\Domain\AgentChatProtocol\Enums\ExternalAgentStatus;
use App\Domain\AgentChatProtocol\Exceptions\A2aDiscoveryException;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\Shared\Services\SsrfGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Discover an external A2A agent by fetching its AgentCard from the RFC 8615
 * well-known URI and registering it as a callable-once-supported ExternalAgent
 * (adapter_kind = a2a).
 *
 * Read/discovery only — message dispatch to A2A agents is a deferred slice and
 * is guarded in ProtocolDispatcher. Flag-gated by `agent_chat.a2a.discovery_enabled`.
 */
class DiscoverA2aAgentAction
{
    public function __construct(private readonly SsrfGuard $ssrfGuard) {}

    /**
     * @param  string  $url  the agent's domain/base URL, or a full AgentCard URL
     *
     * @throws A2aDiscoveryException
     */
    public function execute(
        string $teamId,
        string $url,
        ?string $credentialId = null,
    ): ExternalAgent {
        if (! (bool) config('agent_chat.a2a.discovery_enabled', false)) {
            throw new A2aDiscoveryException('A2A discovery is disabled. Set A2A_DISCOVERY_ENABLED=true to enable it.');
        }

        $cardUrl = $this->resolveCardUrl($url);
        $this->ssrfGuard->assertPublicUrl($cardUrl);

        try {
            $response = Http::timeout((int) config('agent_chat.outbound.timeout_seconds', 30))
                ->acceptJson()
                ->get($cardUrl);
        } catch (\Throwable $e) {
            throw new A2aDiscoveryException("Failed to fetch A2A AgentCard from {$cardUrl}: ".$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new A2aDiscoveryException("A2A AgentCard fetch returned HTTP {$response->status()} from {$cardUrl}.");
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            throw new A2aDiscoveryException("A2A AgentCard at {$cardUrl} is not a JSON object.");
        }

        $card = A2aAgentCard::fromArray($decoded);

        return $this->upsert($teamId, $card, $credentialId);
    }

    /**
     * Append the well-known AgentCard path when a bare domain/base URL is given;
     * pass a URL that already points at a card document through unchanged.
     */
    private function resolveCardUrl(string $url): string
    {
        $url = trim($url);

        if (str_contains($url, '/.well-known/agent')) {
            return $url;
        }

        if (str_ends_with($url, '.json')) {
            return $url;
        }

        $path = (string) config('agent_chat.a2a.well_known_path', '/.well-known/agent-card.json');

        return rtrim($url, '/').'/'.ltrim($path, '/');
    }

    private function upsert(string $teamId, A2aAgentCard $card, ?string $credentialId): ExternalAgent
    {
        $existing = ExternalAgent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('endpoint_url', $card->endpointUrl)
            ->first();

        $attributes = [
            'name' => $card->name,
            'description' => $card->description,
            'adapter_kind' => AdapterKind::A2a->value,
            'manifest_cached' => $card->raw,
            'manifest_fetched_at' => now(),
            'capabilities' => $card->toCapabilities(),
            'metadata' => $card->toMetadata(),
            'protocol_version' => 'a2a',
            'status' => ExternalAgentStatus::Active,
        ];

        if ($credentialId !== null) {
            $attributes['credential_id'] = $credentialId;
        }

        if ($existing !== null) {
            $existing->forceFill($attributes)->save();

            return $existing->refresh();
        }

        return ExternalAgent::create([
            'id' => Str::uuid7()->toString(),
            'team_id' => $teamId,
            'slug' => Str::slug($card->name).'-'.substr(Str::uuid7()->toString(), 0, 6),
            'endpoint_url' => $card->endpointUrl,
            'credential_id' => $credentialId,
            ...$attributes,
        ]);
    }
}
