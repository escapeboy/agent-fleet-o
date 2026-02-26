<?php

namespace App\Http\Controllers;

use App\Domain\Integration\Actions\HandleInboundWebhookAction;
use App\Domain\Integration\Models\WebhookRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationWebhookController extends Controller
{
    public function __construct(
        private readonly HandleInboundWebhookAction $handler,
    ) {}

    /**
     * Receive an inbound webhook and ingest it as signal(s).
     *
     * POST /integrations/webhook/{slug}
     */
    public function handle(Request $request, string $slug): JsonResponse
    {
        $webhookRoute = WebhookRoute::where('slug', $slug)
            ->where('is_active', true)
            ->with('integration')
            ->first();

        if (! $webhookRoute) {
            return response()->json(['error' => 'Webhook endpoint not found.'], 404);
        }

        $status = $this->handler->execute($webhookRoute, $request);

        return response()->json(['status' => 'ok'], $status);
    }
}
