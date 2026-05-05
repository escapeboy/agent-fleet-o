<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Enums\AgentChatVisibility;
use App\Domain\AgentChatProtocol\Services\AgentManifestCache;
use App\Domain\Shared\Models\Team;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function a2a(Request $request, Agent $agent): JsonResponse
    {
        $teamId = $request->user()->current_team_id;
        if ($agent->team_id !== $teamId) {
            abort(403, 'Forbidden');
        }

        $team = Team::withoutGlobalScopes()->find($teamId);
        $slug = $agent->chat_protocol_slug ?? $agent->id;
        $skills = $agent->skills()->get()->map(fn ($skill) => [
            'id' => $skill->id,
            'name' => $skill->name,
            'description' => (string) ($skill->description ?? ''),
            'tags' => $skill->meta['tags'] ?? [],
            'examples' => [],
        ])->values()->toArray();

        return response()->json([
            'schemaVersion' => '0.3',
            'name' => $agent->name,
            'description' => (string) ($agent->description ?? $agent->goal ?? ''),
            'url' => url('/api/v1/agents/'.$agent->id.'/chat'),
            'provider' => [
                'organization' => $team?->name ?? 'FleetQ',
                'url' => url('/'),
            ],
            'version' => '1.0.0',
            'documentationUrl' => url('/agents/'.$slug),
            'capabilities' => [
                'streaming' => true,
                'pushNotifications' => false,
                'stateTransitionHistory' => true,
            ],
            'authentication' => [
                'schemes' => ['Bearer'],
                'credentials' => null,
            ],
            'defaultInputModes' => ['text/plain', 'application/json'],
            'defaultOutputModes' => ['text/plain', 'application/json'],
            'skills' => $skills,
        ]);
    }
}
