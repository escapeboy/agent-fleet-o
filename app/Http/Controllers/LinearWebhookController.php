<?php

namespace App\Http\Controllers;

use App\Domain\Signal\Connectors\LinearConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LinearWebhookController extends Controller
{
    public function __construct(
        private readonly LinearConnector $connector,
    ) {}

    /**
     * Handle Linear webhook.
     *
     * POST /api/signals/linear
     * Headers: Linear-Signature (raw hex), Linear-Event, Linear-Delivery
     */
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('services.linear.webhook_secret');

        if ($secret) {
            $signatureHeader = $request->header('Linear-Signature', '');

            if (! LinearConnector::validateSignature($request->getContent(), $signatureHeader, $secret)) {
                Log::warning('LinearWebhookController: Invalid signature', [
                    'delivery' => $request->header('Linear-Delivery'),
                ]);

                return response()->json(['error' => 'Invalid signature'], 401);
            }
        }

        $payload = $request->all();

        if (empty($payload)) {
            return response()->json(['error' => 'Empty payload'], 422);
        }

        $signals = $this->connector->poll([
            'payload' => $payload,
        ]);

        return response()->json([
            'ingested' => count($signals),
            'signal_ids' => array_map(fn ($s) => $s->id, $signals),
        ], count($signals) > 0 ? 201 : 200);
    }
}
