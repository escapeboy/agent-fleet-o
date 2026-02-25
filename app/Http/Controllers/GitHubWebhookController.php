<?php

namespace App\Http\Controllers;

use App\Domain\Signal\Connectors\GitHubWebhookConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handle GitHub webhook events (push, pull_request, issues, workflow_run, release).
 *
 * POST /api/signals/github
 * Headers: X-Hub-Signature-256, X-GitHub-Event, X-GitHub-Delivery
 */
class GitHubWebhookController extends Controller
{
    /** Events that create signals (ping is handled inline, others are ignored). */
    private const SUPPORTED_EVENTS = ['issues', 'pull_request', 'push', 'workflow_run', 'release'];

    public function __construct(
        private readonly GitHubWebhookConnector $connector,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $event = $request->header('X-GitHub-Event', '');

        // Respond to ping immediately before signature check
        if ($event === 'ping') {
            return response()->json(['message' => 'pong'], 200);
        }

        $secret = config('services.github.webhook_secret');

        if ($secret) {
            $signatureHeader = $request->header('X-Hub-Signature-256', '');

            if (! GitHubWebhookConnector::validateSignature($request->getContent(), $signatureHeader, $secret)) {
                Log::warning('GitHubWebhookController: Invalid signature', [
                    'event' => $event,
                    'delivery' => $request->header('X-GitHub-Delivery'),
                ]);

                return response()->json(['error' => 'Invalid signature'], 403);
            }
        }

        if (! in_array($event, self::SUPPORTED_EVENTS, true)) {
            return response()->json(['message' => "Event '{$event}' not handled"], 200);
        }

        $payload = $request->all();

        if (empty($payload)) {
            return response()->json(['error' => 'Empty payload'], 422);
        }

        $signals = $this->connector->poll([
            'payload' => $payload,
            'event' => $event,
        ]);

        return response()->json([
            'ingested' => count($signals),
            'event' => $event,
            'signal_ids' => array_map(fn ($s) => $s->id, $signals),
        ], count($signals) > 0 ? 201 : 200);
    }
}
