<?php

namespace App\Domain\Outbound\Connectors;

use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use Illuminate\Support\Facades\Http;

/**
 * Signal Protocol outbound connector via signal-cli-rest-api sidecar.
 *
 * Requires the `bbernhard/signal-cli-rest-api` sidecar running alongside the app.
 * Credentials expected in outbound proposal target or team connector config:
 *   api_url      — sidecar base URL (e.g. http://signal-sidecar:8080)
 *   phone_number — registered Signal number to send from
 *   recipient    — recipient phone number
 */
class SignalProtocolConnector implements OutboundConnectorInterface
{
    public function send(OutboundProposal $proposal): OutboundAction
    {
        $idempotencyKey = hash('xxh128', "signal_protocol|{$proposal->id}");

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
            $content = $proposal->content;

            $apiUrl = rtrim($target['api_url'] ?? config('services.signal.api_url', 'http://signal-sidecar:8080'), '/');
            $phoneNumber = $target['phone_number'] ?? config('services.signal.phone_number');
            $recipient = $target['recipient'] ?? $target['phone'] ?? null;

            if (! $phoneNumber || ! $recipient) {
                throw new \InvalidArgumentException('signal_protocol connector requires phone_number and recipient');
            }

            $text = $content['body'] ?? $content['text'] ?? 'No content generated.';

            $response = Http::timeout(15)->post("{$apiUrl}/v2/send", [
                'number' => $phoneNumber,
                'recipients' => [$recipient],
                'message' => $text,
            ]);

            if ($response->successful()) {
                $action->update([
                    'status' => OutboundActionStatus::Sent,
                    'external_id' => (string) ($response->json('timestamp') ?? ''),
                    'response' => $response->json(),
                    'sent_at' => now(),
                ]);
            } else {
                $action->update([
                    'status' => OutboundActionStatus::Failed,
                    'response' => ['error' => $response->body()],
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
        return $channel === 'signal_protocol';
    }
}
