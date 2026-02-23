<?php

namespace App\Http\Controllers;

use App\Domain\Signal\Connectors\GitHubIssuesConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GitHubIssueWebhookController extends Controller
{
    public function __construct(
        private readonly GitHubIssuesConnector $connector,
    ) {}

    /**
     * Handle GitHub Issues webhook.
     *
     * POST /api/signals/github-issues
     * Headers: X-Hub-Signature-256, X-GitHub-Event, X-GitHub-Delivery
     */
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('services.github.webhook_secret');

        if ($secret) {
            $signatureHeader = $request->header('X-Hub-Signature-256', '');

            if (! GitHubIssuesConnector::validateSignature($request->getContent(), $signatureHeader, $secret)) {
                Log::warning('GitHubIssueWebhookController: Invalid signature', [
                    'delivery' => $request->header('X-GitHub-Delivery'),
                ]);

                return response()->json(['error' => 'Invalid signature'], 403);
            }
        }

        $event = $request->header('X-GitHub-Event', '');

        // Only handle 'issues' events
        if ($event && $event !== 'issues' && $event !== 'ping') {
            return response()->json(['message' => 'Event ignored'], 200);
        }

        // Respond to ping immediately
        if ($event === 'ping') {
            return response()->json(['message' => 'pong'], 200);
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
