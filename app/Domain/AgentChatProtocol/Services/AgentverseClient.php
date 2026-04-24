<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Services;

use App\Domain\Credential\Models\Credential;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Client for the public Agentverse endpoints used by FleetQ.
 *
 * Key findings from 2026-04-24 preflight probe:
 *   - Public agent search: POST https://agentverse.ai/v1/search/agents (NO auth)
 *   - Mailbox submit:      POST https://agentverse.ai/v2/agents/mailbox/submit (NO auth)
 *   - Proxy submit:        POST https://agentverse.ai/v2/agents/proxy/submit (NO auth)
 *   - GET /v2/agents is "my own registered agents" (auth-required) — NOT marketplace
 *
 * The ASI:One API key (api.asi1.ai) is ONLY for chat completions, not for the
 * Agentverse management API. Since browse + message submission are public, this
 * client does not need a credential at all; it accepts one optionally for future
 * authenticated endpoints (user's own agents, mailbox polling, etc.).
 */
class AgentverseClient
{
    public const BASE_URL = 'https://agentverse.ai';

    public const SEARCH_URL = self::BASE_URL.'/v1/search/agents';

    public const MAILBOX_URL = self::BASE_URL.'/v2/agents/mailbox/submit';

    public const PROXY_URL = self::BASE_URL.'/v2/agents/proxy/submit';

    public function __construct(private readonly ?string $apiKey = null) {}

    /**
     * Build a client optionally authenticated with a team's Agentverse credential.
     * Returns a client even without a credential — public endpoints work unauthenticated.
     */
    public static function forTeam(string $teamId): self
    {
        /** @var Credential|null $credential */
        $credential = Credential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('status', 'active')
            ->where(function ($q): void {
                $q->whereJsonContains('metadata->provider', 'agentverse');
            })
            ->first();

        $apiKey = null;
        if ($credential !== null) {
            $secretData = (array) $credential->secret_data;
            $candidate = $secretData['api_key'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                $apiKey = $candidate;
            }
        }

        return new self($apiKey);
    }

    /**
     * Search the public Agentverse marketplace.
     *
     * Response shape: { agents: [...], offset, limit, num_hits, total, search_id }
     *
     * @param  array<string, mixed>  $filters  Accepted keys: search_text, limit, offset
     * @return array<int, array<string, mixed>>
     */
    public function listAgents(array $filters = []): array
    {
        $body = [
            'search_text' => (string) ($filters['search_text'] ?? $filters['search'] ?? $filters['query'] ?? ''),
            'limit' => (int) ($filters['limit'] ?? 25),
            'offset' => (int) ($filters['offset'] ?? 0),
        ];

        $response = $this->publicRequest()->post(self::SEARCH_URL, $body);
        if (! $response->successful()) {
            throw new \RuntimeException("Agentverse search failed: HTTP {$response->status()}");
        }

        $json = (array) $response->json();

        return (array) ($json['agents'] ?? []);
    }

    /**
     * Submit an envelope to the public mailbox.
     *
     * @param  array<string, mixed>  $envelope  Must conform to {version:int, sender, target, session:uuid4, schema_digest, payload:string}
     * @return array<string, mixed>
     */
    public function submitMailbox(array $envelope): array
    {
        $response = $this->publicRequest()->post(self::MAILBOX_URL, $envelope);
        if (! $response->successful()) {
            throw new \RuntimeException("Agentverse mailbox submit failed: HTTP {$response->status()} {$response->body()}");
        }

        return (array) $response->json();
    }

    /**
     * Submit an envelope to the public proxy endpoint (synchronous forwarding).
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function submitProxy(array $envelope): array
    {
        $response = $this->publicRequest()->post(self::PROXY_URL, $envelope);
        if (! $response->successful()) {
            throw new \RuntimeException("Agentverse proxy submit failed: HTTP {$response->status()} {$response->body()}");
        }

        return (array) $response->json();
    }

    private function publicRequest(): PendingRequest
    {
        $req = Http::acceptJson()
            ->timeout(30)
            ->withHeaders([
                'User-Agent' => 'FleetQ-AgentverseClient/1.1',
                'Content-Type' => 'application/json',
            ]);

        // Attach auth if we have a key — harmless for public endpoints; required for any
        // future authenticated endpoints (user's own agents, mailbox polling).
        if ($this->apiKey !== null) {
            $req = $req->withToken($this->apiKey);
        }

        return $req;
    }
}
