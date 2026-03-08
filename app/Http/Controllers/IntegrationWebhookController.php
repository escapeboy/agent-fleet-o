<?php

namespace App\Http\Controllers;

use App\Domain\Integration\Actions\HandleInboundWebhookAction;
use App\Domain\Integration\Models\WebhookRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class IntegrationWebhookController extends Controller
{
    public function __construct(
        private readonly HandleInboundWebhookAction $handler,
    ) {}

    /**
     * WhatsApp (Meta) webhook verification challenge.
     *
     * GET /integrations/webhook/{slug}?hub.mode=subscribe&hub.challenge=xxx&hub.verify_token=yyy
     */
    public function challenge(Request $request, string $slug): Response|JsonResponse
    {
        $webhookRoute = WebhookRoute::where('slug', $slug)
            ->where('is_active', true)
            ->with('integration')
            ->first();

        if (! $webhookRoute) {
            return response()->json(['error' => 'Webhook endpoint not found.'], 404);
        }

        if ($request->query('hub_mode') !== 'subscribe' && $request->query('hub.mode') !== 'subscribe') {
            return response()->json(['error' => 'Invalid challenge request.'], 400);
        }

        $verifyToken = $webhookRoute->integration?->getCredentialSecret('verify_token') ?? '';
        $incoming    = $request->query('hub_verify_token') ?? $request->query('hub.verify_token', '');

        if (! hash_equals($verifyToken, (string) $incoming)) {
            return response()->json(['error' => 'Verify token mismatch.'], 403);
        }

        $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge', '');

        return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
    }

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
