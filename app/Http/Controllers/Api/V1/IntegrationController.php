<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integration\Actions\ConnectIntegrationAction;
use App\Domain\Integration\Actions\DisconnectIntegrationAction;
use App\Domain\Integration\Actions\ExecuteIntegrationActionAction;
use App\Domain\Integration\Actions\PingIntegrationAction;
use App\Domain\Integration\Models\Integration;
use App\Domain\Integration\Services\IntegrationManager;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\IntegrationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Integrations
 */
class IntegrationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $integrations = QueryBuilder::for(Integration::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('driver'),
                AllowedFilter::partial('name'),
            ])
            ->allowedSorts(['created_at', 'name', 'status', 'driver'])
            ->defaultSort('-created_at')
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return IntegrationResource::collection($integrations);
    }

    public function show(Integration $integration): IntegrationResource
    {
        return new IntegrationResource($integration);
    }

    public function connect(Request $request, ConnectIntegrationAction $action): JsonResponse
    {
        $request->validate([
            'driver'      => ['required', 'string', 'max:64'],
            'name'        => ['required', 'string', 'max:255'],
            'credentials' => ['sometimes', 'array'],
            'config'      => ['sometimes', 'array'],
        ]);

        try {
            $integration = $action->execute(
                teamId: $request->user()->current_team_id,
                driver: $request->input('driver'),
                name: $request->input('name'),
                credentials: $request->input('credentials', []),
                config: $request->input('config', []),
            );

            return (new IntegrationResource($integration))
                ->response()
                ->setStatusCode(201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function disconnect(Integration $integration, DisconnectIntegrationAction $action): JsonResponse
    {
        $action->execute($integration);

        return response()->json(['success' => true, 'message' => 'Integration disconnected.']);
    }

    /**
     * @response 200 {"healthy": true, "message": "OK", "latency_ms": 120, "checked_at": "..."}
     */
    public function ping(Integration $integration, PingIntegrationAction $action): JsonResponse
    {
        $result = $action->execute($integration);

        return response()->json([
            'healthy'    => $result->healthy,
            'message'    => $result->message,
            'latency_ms' => $result->latencyMs,
            'checked_at' => $result->checkedAt?->toISOString(),
        ]);
    }

    /**
     * @response 200 {"success": true, "result": {}}
     */
    public function execute(Request $request, Integration $integration, ExecuteIntegrationActionAction $action): JsonResponse
    {
        $request->validate([
            'action' => ['required', 'string', 'max:128'],
            'params' => ['sometimes', 'array'],
        ]);

        try {
            $result = $action->execute(
                integration: $integration,
                action: $request->input('action'),
                params: $request->input('params', []),
            );

            return response()->json(['success' => true, 'result' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * @response 200 {"driver": "github", "actions": [], "triggers": []}
     */
    public function capabilities(Integration $integration, IntegrationManager $manager): JsonResponse
    {
        $driver = $manager->driver($integration->driver);

        return response()->json([
            'driver'   => $integration->driver,
            'actions'  => array_map(fn ($a) => [
                'key'          => $a->key,
                'label'        => $a->label,
                'description'  => $a->description,
                'input_schema' => $a->inputSchema,
            ], $driver->actions()),
            'triggers' => array_map(fn ($t) => [
                'key'         => $t->key,
                'label'       => $t->label,
                'description' => $t->description,
            ], $driver->triggers()),
        ]);
    }
}
