<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\DTOs\AgentManifestDTO;
use Illuminate\Support\Facades\Cache;

class AgentManifestCache
{
    public function __construct(private readonly AgentManifestBuilder $builder) {}

    public function get(Agent $agent): AgentManifestDTO
    {
        $ttl = (int) config('agent_chat.manifest.cache_seconds', 300);
        $key = $this->cacheKey($agent);

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $this->hydrate($cached);
        }

        $manifest = $this->builder->build($agent);
        Cache::put($key, $manifest->toArray(), $ttl);

        return $manifest;
    }

    public function forget(Agent $agent): void
    {
        Cache::forget($this->cacheKey($agent));
    }

    private function cacheKey(Agent $agent): string
    {
        return 'agent_chat_manifest:'.$agent->id.':'.($agent->updated_at?->timestamp ?? 0);
    }

    private function hydrate(array $data): AgentManifestDTO
    {
        return new AgentManifestDTO(
            identifier: (string) $data['identifier'],
            name: (string) $data['name'],
            description: (string) $data['description'],
            protocolUri: (string) $data['protocol'],
            supportedMessageTypes: (array) $data['supported_message_types'],
            endpoint: (string) $data['endpoint'],
            authScheme: (string) $data['auth_scheme'],
            capabilities: (array) $data['capabilities'],
            fleetqExtension: (array) $data['fleetq_extension'],
            version: (string) $data['manifest_version'],
        );
    }
}
