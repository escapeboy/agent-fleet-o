<?php

namespace App\Http\Controllers;

use App\Domain\Signal\Connectors\WebhookConnector;
use App\Infrastructure\Security\IpReputationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SignalWebhookController extends Controller
{
    public function __construct(
        private readonly WebhookConnector $webhookConnector,
        private readonly IpReputationService $ipReputation,
    ) {}

    /**
     * Receive a webhook POST and ingest it as a signal.
     *
     * POST /api/signals/webhook
     * Headers: X-Webhook-Signature (optional HMAC-SHA256)
     */
    public function __invoke(Request $request): JsonResponse
    {
        // In production, a webhook secret is mandatory — fail closed if not configured.
        $secret = config('services.signal_webhook.secret');
        if (! $secret && app()->isProduction()) {
            Log::warning('SignalWebhookController: SIGNAL_WEBHOOK_SECRET not configured in production');

            return response()->json(['error' => 'Webhook not configured'], 403);
        }

        if ($secret) {
            $signature = $request->header('X-Webhook-Signature', '');
            if (! WebhookConnector::validateSignature($request->getContent(), $signature, $secret)) {
                Log::warning('SignalWebhookController: Invalid signature');

                return response()->json(['error' => 'Invalid signature'], 403);
            }
        }

        if (config('security.ip_reputation.enabled', true)) {
            $clientIp = $request->ip() ?? '';
            $result = $this->ipReputation->check($clientIp);
            $threshold = (int) config('security.ip_reputation.block_threshold', 75);

            if ($result->isHighRisk($threshold)) {
                Log::warning('SignalWebhookController: high-risk IP blocked', [
                    'ip' => $clientIp,
                    'abuse_score' => $result->abuseScore,
                    'is_tor' => $result->isTor,
                ]);

                if (! config('security.ip_reputation.log_only', false)) {
                    return response()->json(['error' => 'high_risk_ip'], 403);
                }
            }
        }

        $payload = $request->all();

        if (empty($payload)) {
            return response()->json(['error' => 'Empty payload'], 422);
        }

        $signals = $this->webhookConnector->poll([
            'payload' => $payload,
            'source' => $request->header('X-Webhook-Source', $request->ip()),
            'experiment_id' => $request->input('experiment_id'),
            'tags' => $request->input('tags', ['webhook']),
            'files' => $request->allFiles(),
        ]);

        return response()->json([
            'ingested' => count($signals),
            'signal_ids' => array_map(fn ($s) => $s->id, $signals),
        ], count($signals) > 0 ? 201 : 200);
    }
}
