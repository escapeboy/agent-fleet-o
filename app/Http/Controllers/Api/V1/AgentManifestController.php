<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Enums\AgentChatVisibility;
use App\Domain\AgentChatProtocol\Services\AgentManifestCache;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Public Agent Chat Protocol manifest.
 *
 * @tags Agent Chat Protocol
 */
class AgentManifestController extends Controller
{
    public function __construct(private readonly AgentManifestCache $cache) {}

    public function index(): JsonResponse
    {
        $agents = Agent::withoutGlobalScopes()
            ->where('chat_protocol_enabled', true)
            ->whereIn('chat_protocol_visibility', [
                AgentChatVisibility::Marketplace->value,
                AgentChatVisibility::Public->value,
            ])
            ->limit(500)
            ->get();

        $listings = $agents->map(fn (Agent $agent) => [
            'slug' => $agent->chat_protocol_slug ?? $agent->id,
            'name' => $agent->name,
            'description' => (string) ($agent->description ?? ''),
            'manifest_url' => url('/.well-known/agents/'.($agent->chat_protocol_slug ?? $agent->id)),
        ])->values();

        return response()->json([
            'agents' => $listings,
            'protocol' => (string) config('agent_chat.protocol_manifest_uri'),
        ])->header('Access-Control-Allow-Origin', '*');
    }

    public function show(string $slug): JsonResponse
    {
        /** @var Agent|null $agent */
        $agent = Agent::withoutGlobalScopes()
            ->where('chat_protocol_enabled', true)
            ->whereIn('chat_protocol_visibility', [
                AgentChatVisibility::Marketplace->value,
                AgentChatVisibility::Public->value,
            ])
            ->where(function ($q) use ($slug): void {
                $q->where('chat_protocol_slug', $slug)->orWhere('id', $slug);
            })
            ->first();

        if ($agent === null) {
            abort(404, 'Agent not found');
        }

        $manifest = $this->cache->get($agent);

        return response()->json($manifest->toArray())
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'public, max-age='.(int) config('agent_chat.manifest.cache_seconds', 300));
    }
}
