<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Actions;

use App\Domain\AgentChatProtocol\Exceptions\ManifestFetchException;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\Shared\Services\SsrfGuard;
use Illuminate\Support\Facades\Http;

class RefreshExternalAgentManifestAction
{
    public function __construct(private readonly SsrfGuard $ssrfGuard) {}

    public function execute(ExternalAgent $externalAgent): ExternalAgent
    {
        $target = $externalAgent->manifest_url ?? rtrim($externalAgent->endpoint_url, '/').'/manifest';
        $this->ssrfGuard->assertPublicUrl($target);

        try {
            $response = Http::timeout(10)->acceptJson()->get($target);
            if (! $response->successful()) {
                throw new ManifestFetchException("Manifest fetch returned HTTP {$response->status()}");
            }
            $manifest = (array) $response->json();
        } catch (\Throwable $e) {
            throw new ManifestFetchException('Failed to refresh manifest: '.$e->getMessage(), 0, $e);
        }

        $externalAgent->forceFill([
            'manifest_cached' => $manifest,
            'manifest_fetched_at' => now(),
            'capabilities' => [
                'supported_message_types' => $manifest['supported_message_types'] ?? [],
                'streaming' => $manifest['capabilities']['streaming'] ?? false,
                'async' => $manifest['capabilities']['async'] ?? false,
            ],
            'protocol_version' => $manifest['manifest_version'] ?? $externalAgent->protocol_version,
        ])->save();

        return $externalAgent->refresh();
    }
}
