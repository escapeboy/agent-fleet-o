<?php

namespace App\Http\Controllers;

use App\Domain\Signal\Connectors\SentryAlertConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SentryAlertWebhookController extends Controller
{
    public function __construct(
        private readonly SentryAlertConnector $connector,
    ) {}

    /**
     * Handle Sentry alert webhook.
     *
     * POST /api/signals/sentry
     * Headers: Sentry-Hook-Signature (raw hex), Sentry-Hook-Resource, Sentry-Hook-Timestamp
     *
     * Note: Sentry requires responses within 1 second — processing is queued automatically
     * via the IngestSignalAction dispatch chain.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('services.sentry.client_secret');

        if ($secret) {
            $signatureHeader = $request->header('Sentry-Hook-Signature', '');

            if (! SentryAlertConnector::validateSignature($request->getContent(), $signatureHeader, $secret)) {
                Log::warning('SentryAlertWebhookController: Invalid signature');

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
