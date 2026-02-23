<?php

namespace App\Http\Controllers;

use App\Domain\Signal\Connectors\JiraConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class JiraWebhookController extends Controller
{
    public function __construct(
        private readonly JiraConnector $connector,
    ) {}

    /**
     * Handle Jira webhook.
     *
     * POST /api/signals/jira
     * Headers: X-Hub-Signature (optional, format: sha256=<hex>)
     */
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('services.jira.webhook_secret');

        if ($secret) {
            $signatureHeader = $request->header('X-Hub-Signature', '');

            if (! JiraConnector::validateSignature($request->getContent(), $signatureHeader, $secret)) {
                Log::warning('JiraWebhookController: Invalid signature', [
                    'identifier' => $request->header('X-Atlassian-Webhook-Identifier'),
                ]);

                return response()->json(['error' => 'Invalid signature'], 403);
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
