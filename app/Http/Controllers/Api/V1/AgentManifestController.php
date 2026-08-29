<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Enums\AgentChatVisibility;
use App\Domain\AgentChatProtocol\Services\AgentManifestCache;
use App\Domain\Shared\Models\Team;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
            ->where(fn ($q) => $this->matchSlugOrId($q, $slug))
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

        return response()->json($this->buildA2aCard($agent));
    }

    /**
     * Public A2A AgentCard at the RFC 8615 well-known URI.
     *
     * An external peer has no Sanctum token, so without this route the card was
     * only reachable by the team that already owns the agent — which makes A2A
     * discovery impossible for the one audience it exists for. Only agents the
     * team has deliberately published (marketplace/public) are exposed.
     */
    public function publicA2a(string $slug): JsonResponse
    {
        $agent = Agent::withoutGlobalScopes()
            ->where('chat_protocol_enabled', true)
            ->where(fn ($q) => $this->matchSlugOrId($q, $slug))
            ->first();

        $visibility = $agent === null
            ? null
            : AgentChatVisibility::normalize($agent->getAttribute('chat_protocol_visibility'));

        if ($visibility === null || ! $visibility->allowsPublicManifest()) {
            abort(404, 'Agent not available on the chat protocol');
        }

        return response()->json($this->buildA2aCard($agent));
    }

    /**
     * Match a public identifier against the slug, and against the id only when
     * it could actually be one.
     *
     * `agents.id` is a Postgres `uuid` column: comparing it to an arbitrary
     * string is not a miss, it is SQLSTATE 22P02 and a 500. Every public
     * chat_protocol_slug is non-UUID by construction, so the unguarded
     * `orWhere('id', $slug)` broke this endpoint for exactly the inputs it
     * exists to serve. SQLite (the test database) accepts the comparison, which
     * is why no test caught it.
     */
    private function matchSlugOrId(Builder $query, string $slug): Builder
    {
        $query->where('chat_protocol_slug', $slug);

        if (Str::isUuid($slug)) {
            $query->orWhere('id', $slug);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildA2aCard(Agent $agent): array
    {
        $team = Team::withoutGlobalScopes()->find($agent->team_id);
        $slug = $agent->chat_protocol_slug ?? $agent->id;
        $skills = $agent->skills()->get()->map(fn ($skill) => [
            'id' => $skill->id,
            'name' => $skill->name,
            'description' => (string) ($skill->description ?? ''),
            'tags' => $skill->meta['tags'] ?? [],
            'examples' => [],
        ])->values()->toArray();

        return [
            'schemaVersion' => '0.3',
            'name' => $agent->name,
            'description' => (string) ($agent->description ?? $agent->goal ?? ''),
            // An AgentCard's `url` is where a peer sends A2A JSON-RPC. Pointing it
            // at our REST chat endpoint made the card unusable: a conforming
            // client would POST message/send and get our own envelope back.
            'url' => url('/api/v1/agents/'.$agent->id.'/a2a'),
            'preferredTransport' => 'JSONRPC',
            'supportedInterfaces' => [[
                'url' => url('/api/v1/agents/'.$agent->id.'/a2a'),
                'protocolBinding' => 'JSONRPC',
                'protocolVersion' => '0.3',
            ]],
            'provider' => [
                'organization' => $team?->name ?? 'FleetQ',
                'url' => url('/'),
            ],
            'version' => '1.0.0',
            'documentationUrl' => url('/agents/'.$slug),
            'capabilities' => [
                'streaming' => false,
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
        ];
    }
}
