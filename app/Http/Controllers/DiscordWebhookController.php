<?php

namespace App\Http\Controllers;

use App\Domain\Signal\Connectors\DiscordWebhookConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DiscordWebhookController extends Controller
{
    public function __construct(
        private readonly DiscordWebhookConnector $connector,
    ) {}

    /**
     * Handle Discord Interactions endpoint.
     *
     * POST /api/signals/discord
     * Validates Ed25519 signature and handles PING/MESSAGE interactions.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $publicKey = config('services.discord.public_key');

        if ($publicKey) {
            $signature = $request->header('X-Signature-Ed25519', '');
            $timestamp = $request->header('X-Signature-Timestamp', '');

            if (! DiscordWebhookConnector::validateSignature($timestamp, $request->getContent(), $signature, $publicKey)) {
                Log::warning('DiscordWebhookController: Invalid signature');

                return response()->json(['error' => 'Invalid signature'], 401);
            }
        }

        $payload = $request->all();

        if (empty($payload)) {
            return response()->json(['error' => 'Empty payload'], 422);
        }

        // Handle PING interaction (type 1) — Discord verification
        $type = $payload['type'] ?? 0;
        if ($type === 1) {
            return response()->json(['type' => 1]);
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
