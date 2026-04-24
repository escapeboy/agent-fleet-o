<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Actions;

use App\Domain\AgentChatProtocol\Enums\ExternalAgentStatus;
use App\Domain\AgentChatProtocol\Exceptions\ManifestFetchException;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\Shared\Services\SsrfGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RegisterExternalAgentAction
{
    public function __construct(private readonly SsrfGuard $ssrfGuard) {}

    public function execute(
        string $teamId,
        string $name,
        string $endpointUrl,
        ?string $manifestUrl = null,
        ?string $credentialId = null,
        ?string $description = null,
    ): ExternalAgent {
        $this->ssrfGuard->assertPublicUrl($endpointUrl);
        if ($manifestUrl !== null) {
            $this->ssrfGuard->assertPublicUrl($manifestUrl);
        }

        $slug = Str::slug($name).'-'.substr(Str::uuid7()->toString(), 0, 6);

        $manifestCached = null;
        $capabilities = null;
        $manifestFetchedAt = null;

        $manifestTarget = $manifestUrl ?? rtrim($endpointUrl, '/').'/manifest';

        try {
            $response = Http::timeout(10)->acceptJson()->get($manifestTarget);
            if ($response->successful()) {
                $manifestCached = (array) $response->json();
                $capabilities = [
                    'supported_message_types' => $manifestCached['supported_message_types'] ?? [],
                    'streaming' => $manifestCached['capabilities']['streaming'] ?? false,
                    'async' => $manifestCached['capabilities']['async'] ?? false,
                ];
                $manifestFetchedAt = now();
            }
        } catch (\Throwable $e) {
            throw new ManifestFetchException("Failed to fetch manifest from {$manifestTarget}: ".$e->getMessage(), 0, $e);
        }

        return ExternalAgent::create([
            'id' => Str::uuid7()->toString(),
            'team_id' => $teamId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'endpoint_url' => $endpointUrl,
            'manifest_url' => $manifestUrl,
            'manifest_cached' => $manifestCached,
            'manifest_fetched_at' => $manifestFetchedAt,
            'capabilities' => $capabilities,
            'credential_id' => $credentialId,
            'status' => ExternalAgentStatus::Active,
            'protocol_version' => $manifestCached['manifest_version'] ?? 'asi1-v1',
        ]);
    }
}
