<?php

namespace App\Http\Controllers;

use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Models\OutboundAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Handles WhatsApp Cloud API webhook events for outbound delivery receipts.
 *
 * This controller is distinct from WhatsAppWebhookController (signal ingestion).
 * Its purpose is to receive status updates (sent/delivered/read/failed) for
 * messages previously dispatched by WhatsAppConnector and update OutboundAction.
 *
 * Routes:
 *   GET  /api/whatsapp/webhook/{teamId} — Meta webhook verification challenge
 *   POST /api/whatsapp/webhook/{teamId} — Delivery status update events
 */
class WhatsAppOutboundWebhookController extends Controller
{
    /**
     * Handle WhatsApp webhook verification (GET).
     *
     * Meta sends hub.mode=subscribe, hub.verify_token, and hub.challenge.
     * We respond with the challenge value when the token matches.
     */
    public function verify(Request $request, string $teamId): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $verifyToken = config('services.whatsapp.verify_token');

        if (! $verifyToken) {
            return response('Forbidden', 403);
        }

        if ($mode === 'subscribe' && hash_equals($verifyToken, (string) $token)) {
            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('WhatsAppOutboundWebhookController: Verification failed', [
            'team_id' => $teamId,
            'mode' => $mode,
            'token_match' => $token === $verifyToken,
        ]);

        return response('Forbidden', 403);
    }

    /**
     * Receive WhatsApp delivery status events (POST).
     *
     * Validates the X-Hub-Signature-256 header and processes statuses:
     *   sent, delivered, read → maps to OutboundActionStatus::Sent
     *   failed               → maps to OutboundActionStatus::Failed
     */
    public function receive(Request $request, string $teamId): JsonResponse
    {
        $appSecret = config('services.whatsapp.app_secret');

        // Reject requests when app secret is not configured — never skip HMAC validation
        if (! $appSecret) {
            Log::warning('WhatsAppOutboundWebhookController: app_secret not configured', ['team_id' => $teamId]);

            return response()->json(['error' => 'Forbidden'], 403);
        }

        $signature = $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        if (! hash_equals($expected, $signature)) {
            Log::warning('WhatsAppOutboundWebhookController: Invalid signature', ['team_id' => $teamId]);

            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $payload = $request->all();

        // Meta wraps events in entry[].changes[].value
        $updated = 0;
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                foreach ($value['statuses'] ?? [] as $statusEvent) {
                    $updated += $this->processStatusEvent($statusEvent, $teamId);
                }
            }
        }

        return response()->json(['processed' => $updated]);
    }

    /**
     * Map a single status event to an OutboundAction status update.
     *
     * @param  array<string, mixed>  $statusEvent
     */
    private function processStatusEvent(array $statusEvent, string $teamId): int
    {
        $messageId = $statusEvent['id'] ?? null;
        $status = $statusEvent['status'] ?? null;

        if (! $messageId || ! $status) {
            return 0;
        }

        $action = OutboundAction::withoutGlobalScopes()
            ->where('external_id', $messageId)
            ->where('team_id', $teamId)
            ->first();

        if (! $action) {
            return 0;
        }

        $newStatus = match ($status) {
            'sent', 'delivered', 'read' => OutboundActionStatus::Sent,
            'failed' => OutboundActionStatus::Failed,
            default => null,
        };

        if ($newStatus === null) {
            return 0;
        }

        $action->update([
            'status' => $newStatus,
            'response' => array_merge(
                $action->response ?? [],
                ['delivery_status' => $status, 'delivery_at' => now()->toIso8601String()],
            ),
        ]);

        return 1;
    }
}
