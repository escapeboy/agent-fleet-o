<?php

namespace App\Http\Controllers;

use App\Domain\Signal\Connectors\WhatsAppWebhookConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsAppWebhookConnector $connector,
    ) {}

    /**
     * Handle WhatsApp webhook verification (GET).
     *
     * GET /api/signals/whatsapp
     * Meta sends a challenge to verify webhook ownership.
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $verifyToken = config('services.whatsapp.verify_token');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('WhatsAppWebhookController: Verification failed', [
            'mode' => $mode,
            'token_match' => $token === $verifyToken,
        ]);

        return response('Forbidden', 403);
    }

    /**
     * Receive WhatsApp webhook events (POST).
     *
     * POST /api/signals/whatsapp
     * Validates X-Hub-Signature-256 header using HMAC-SHA256.
     */
    public function handle(Request $request): JsonResponse
    {
        $appSecret = config('services.whatsapp.app_secret');

        if ($appSecret) {
            $signature = $request->header('X-Hub-Signature-256', '');
            if (! WhatsAppWebhookConnector::validateSignature($request->getContent(), $signature, $appSecret)) {
                Log::warning('WhatsAppWebhookController: Invalid signature');

                return response()->json(['error' => 'Invalid signature'], 403);
            }
        }

        $payload = $request->all();

        if (empty($payload)) {
            return response()->json(['error' => 'Empty payload'], 422);
        }

        $signals = $this->connector->poll([
            'payload' => $payload,
            'experiment_id' => $request->input('experiment_id'),
        ]);

        return response()->json([
            'ingested' => count($signals),
            'signal_ids' => array_map(fn ($s) => $s->id, $signals),
        ], count($signals) > 0 ? 201 : 200);
    }
}
