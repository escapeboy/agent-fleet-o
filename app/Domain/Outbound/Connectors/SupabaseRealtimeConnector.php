<?php

namespace App\Domain\Outbound\Connectors;

use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Supabase Realtime Broadcast outbound connector.
 *
 * Pushes agent output to a Supabase Realtime Broadcast channel via the
 * REST broadcast endpoint (no WebSocket connection required from PHP).
 *
 * Target shape:
 * {
 *   "ref":     "xyzabcdef",          // Supabase project reference ID
 *   "channel": "agent:results",      // Realtime channel topic (arbitrary string)
 *   "event":   "step_completed",     // Broadcast event name
 *   "key":     "<anon_or_service_key>" // Supabase apikey (anon or service role)
 * }
 *
 * The proposal content is JSON-decoded and sent as the event payload.
 * Browser clients subscribe with:
 *   supabase.channel('agent:results').on('broadcast', { event: 'step_completed' }, handler).subscribe()
 *
 * @see https://supabase.com/docs/guides/realtime/broadcast
 */
class SupabaseRealtimeConnector implements OutboundConnectorInterface
{
    public function send(OutboundProposal $proposal): OutboundAction
    {
        $idempotencyKey = hash('xxh128', "supabase_realtime|{$proposal->id}");

        $existing = OutboundAction::withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $action = OutboundAction::withoutGlobalScopes()->create([
            'team_id' => $proposal->team_id,
            'outbound_proposal_id' => $proposal->id,
            'status' => OutboundActionStatus::Sending,
            'idempotency_key' => $idempotencyKey,
            'retry_count' => 0,
        ]);

        try {
            $target = $proposal->target;
            $ref = $target['ref'] ?? null;
            $channel = $target['channel'] ?? 'agent:results';
            $event = $target['event'] ?? 'message';
            $apiKey = $target['key'] ?? null;

            if (! $ref || ! $apiKey) {
                Log::warning('SupabaseRealtimeConnector: missing ref or key in target', [
                    'proposal_id' => $proposal->id,
                ]);

                $action->update([
                    'status' => OutboundActionStatus::Failed,
                    'response' => ['error' => 'Missing required target fields: ref, key'],
                ]);

                return $action;
            }

            // Decode content to send as structured payload
            $payload = is_string($proposal->content)
                ? (json_decode($proposal->content, true) ?? ['message' => $proposal->content])
                : (array) $proposal->content;

            $payload['_experiment_id'] = $proposal->experiment_id;
            $payload['_proposal_id'] = $proposal->id;

            $broadcastUrl = "https://{$ref}.supabase.co/realtime/v1/api/broadcast";

            $response = Http::withHeaders([
                'apikey' => $apiKey,
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(10)->post($broadcastUrl, [
                'messages' => [
                    [
                        'topic' => $channel,
                        'event' => $event,
                        'payload' => $payload,
                    ],
                ],
            ]);

            if ($response->successful()) {
                $action->update([
                    'status' => OutboundActionStatus::Sent,
                    'external_id' => "supabase-realtime-{$ref}-".now()->timestamp,
                    'response' => ['channel' => $channel, 'event' => $event],
                    'sent_at' => now(),
                ]);
            } else {
                $action->update([
                    'status' => OutboundActionStatus::Failed,
                    'response' => [
                        'status' => $response->status(),
                        'body' => substr($response->body(), 0, 500),
                    ],
                    'retry_count' => $action->retry_count + 1,
                ]);
            }
        } catch (\Throwable $e) {
            $action->update([
                'status' => OutboundActionStatus::Failed,
                'response' => ['error' => $e->getMessage()],
                'retry_count' => $action->retry_count + 1,
            ]);
        }

        return $action;
    }

    public function supports(string $channel): bool
    {
        return $channel === 'supabase_realtime';
    }
}
