<?php

namespace App\Http\Controllers;

use App\Domain\Signal\Connectors\ClearCueConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handle ClearCue GTM signal webhook pushes.
 *
 * POST /api/signals/clearcue
 *
 * ClearCue pushes buyer intent signals when companies in your tracked
 * audience show buying behaviour (LinkedIn engagement, job postings,
 * competitor research, conference activity, etc.).
 *
 * Authentication: HMAC-SHA256 via X-ClearCue-Signature header.
 * Requires CLEARCUE_WEBHOOK_SECRET in .env (Pro plan+).
 *
 * @see https://clearcue.ai/pricing  (webhook on Pro plan)
 * @see https://docs.clearcue.ai/integrations/webhooks
 */
class ClearCueWebhookController extends Controller
{
    public function __construct(
        private readonly ClearCueConnector $connector,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('services.clearcue.webhook_secret');

        if ($secret) {
            // ClearCue signature header — update name below once confirmed from dashboard
            // Known candidates: X-ClearCue-Signature, X-Signature, X-Hub-Signature-256
            $signatureHeader = $request->header('X-ClearCue-Signature', '')
                ?: $request->header('X-Signature', '');

            if (! ClearCueConnector::validateSignature($request->getContent(), $signatureHeader, $secret)) {
                Log::warning('ClearCueWebhookController: Invalid signature', [
                    'ip' => $request->ip(),
                    'headers' => $request->headers->all(),
                ]);

                return response()->json(['error' => 'Invalid signature'], 401);
            }
        }

        $payload = $request->all();

        if (empty($payload)) {
            return response()->json(['error' => 'Empty payload'], 422);
        }

        $teamId = app()->bound('current_team') ? app('current_team')->id : null;

        $signals = $this->connector->poll([
            'payload' => $payload,
            'team_id' => $teamId,
        ]);

        return response()->json([
            'ingested'   => count($signals),
            'signal_ids' => array_map(fn ($s) => $s->id, $signals),
        ], count($signals) > 0 ? 201 : 200);
    }
}
