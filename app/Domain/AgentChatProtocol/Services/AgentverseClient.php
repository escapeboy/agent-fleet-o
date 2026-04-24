<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Services;

use App\Domain\Credential\Models\Credential;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for https://agentverse.ai/v2/ — authenticated via an ASI:One
 * bearer token stored as a Credential on the team (metadata.provider = 'agentverse').
 */
class AgentverseClient
{
    public const BASE_URL = 'https://agentverse.ai/v2';

    public function __construct(private readonly string $apiKey) {}

    /**
     * Resolve the team's Agentverse credential and build a client.
     * Returns null if the team has no Agentverse API key configured.
     */
    public static function forTeam(string $teamId): ?self
    {
        /** @var Credential|null $credential */
        $credential = Credential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('status', 'active')
            ->where(function ($q): void {
                $q->whereJsonContains('metadata->provider', 'agentverse');
            })
            ->first();

        if ($credential === null) {
            return null;
        }

        $secret = $credential->secret_data['api_key'] ?? null;
        if (! is_string($secret) || $secret === '') {
            return null;
        }

        return new self($secret);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function listAgents(array $filters = []): array
    {
        $response = $this->request()->get(self::BASE_URL.'/agents', $filters);
        if (! $response->successful()) {
            throw new \RuntimeException("Agentverse list failed: HTTP {$response->status()}");
        }

        return (array) $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getAgent(string $address): array
    {
        $response = $this->request()->get(self::BASE_URL.'/agents/'.urlencode($address));
        if ($response->status() === 404) {
            throw new \RuntimeException("Agentverse agent {$address} not found");
        }
        if (! $response->successful()) {
            throw new \RuntimeException("Agentverse get failed: HTTP {$response->status()}");
        }

        return (array) $response->json();
    }

    /**
     * Submit a mailbox envelope to deliver a message to a target agent.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function submitMailbox(array $envelope): array
    {
        $response = $this->request()->post(self::BASE_URL.'/agents/mailbox/submit', $envelope);
        if (! $response->successful()) {
            throw new \RuntimeException("Agentverse mailbox submit failed: HTTP {$response->status()} {$response->body()}");
        }

        return (array) $response->json();
    }

    /**
     * Submit a proxy message — for agents exposing an always-online endpoint,
     * Agentverse forwards the request synchronously.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function submitProxy(string $address, array $envelope): array
    {
        $response = $this->request()->post(
            self::BASE_URL.'/agents/'.urlencode($address).'/proxy/submit',
            $envelope,
        );
        if (! $response->successful()) {
            throw new \RuntimeException("Agentverse proxy submit failed: HTTP {$response->status()} {$response->body()}");
        }

        return (array) $response->json();
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(30)
            ->withHeaders([
                'User-Agent' => 'FleetQ-AgentverseClient/1.0',
            ]);
    }
}
