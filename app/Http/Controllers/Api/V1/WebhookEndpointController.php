<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Webhook\Enums\WebhookEvent;
use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\QueryBuilder;

class WebhookEndpointController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $endpoints = QueryBuilder::for(WebhookEndpoint::class)
            ->allowedFilters(['is_active'])
            ->allowedSorts(['created_at', 'name'])
            ->defaultSort('-created_at')
            ->cursorPaginate($request->input('per_page', 15));

        return response()->json($endpoints);
    }

    public function show(WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        return response()->json([
            'data' => $webhookEndpoint->makeHidden('secret'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:2048',
            'events' => 'required|array|min:1',
            'events.*' => 'in:'.implode(',', array_column(WebhookEvent::cases(), 'value')).',*',
            'secret' => 'nullable|string|max:255',
            'headers' => 'nullable|array',
            'retry_config' => 'nullable|array',
            'retry_config.max_retries' => 'nullable|integer|min:0|max:10',
        ]);

        $endpoint = WebhookEndpoint::create([
            'team_id' => $request->user()->current_team_id,
            'name' => $validated['name'],
            'url' => $validated['url'],
            'events' => $validated['events'],
            'secret' => $validated['secret'] ?? Str::random(64),
            'headers' => $validated['headers'] ?? null,
            'retry_config' => $validated['retry_config'] ?? ['max_retries' => 3, 'backoff' => 'exponential'],
        ]);

        return response()->json(['data' => $endpoint->makeHidden('secret')], 201);
    }

    public function update(Request $request, WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'url' => 'sometimes|url|max:2048',
            'events' => 'sometimes|array|min:1',
            'events.*' => 'in:'.implode(',', array_column(WebhookEvent::cases(), 'value')).',*',
            'is_active' => 'sometimes|boolean',
            'headers' => 'nullable|array',
            'retry_config' => 'nullable|array',
        ]);

        $webhookEndpoint->update($validated);

        return response()->json(['data' => $webhookEndpoint->fresh()->makeHidden('secret')]);
    }

    public function destroy(WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $webhookEndpoint->delete();

        return response()->json(null, 204);
    }
}
