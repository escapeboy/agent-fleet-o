<?php

namespace App\Domain\Outbound\Connectors;

use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Matrix Protocol outbound connector via Matrix Client-Server API.
 *
 * Sends messages to a Matrix room using the PUT /_matrix/client/v3/rooms/{roomId}/send endpoint.
 * Credentials expected in proposal target:
 *   homeserver_url — Matrix homeserver (e.g. https://matrix.org)
 *   access_token   — Bot access token
 *   room_id        — Target Matrix room ID (e.g. !abc123:matrix.org)
 */
class MatrixConnector implements OutboundConnectorInterface
{
    public function send(OutboundProposal $proposal): OutboundAction
    {
        $idempotencyKey = hash('xxh128', "matrix|{$proposal->id}");

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

            $homeserverUrl = rtrim($target['homeserver_url'] ?? config('services.matrix.homeserver_url', ''), '/');
            $accessToken = $target['access_token'] ?? config('services.matrix.access_token');
            $roomId = $target['room_id'] ?? null;

            if (! $homeserverUrl || ! $accessToken || ! $roomId) {
                throw new \InvalidArgumentException('matrix connector requires homeserver_url, access_token, and room_id');
            }

            $text = $content['body'] ?? $content['text'] ?? 'No content generated.';
            $txnId = Str::uuid()->toString();
            $encodedRoomId = urlencode($roomId);

            $response = Http::timeout(15)
                ->withToken($accessToken)
                ->put("{$homeserverUrl}/_matrix/client/v3/rooms/{$encodedRoomId}/send/m.room.message/{$txnId}", [
                    'msgtype' => 'm.text',
                    'body' => $text,
                ]);

            if ($response->successful()) {
                $action->update([
                    'status' => OutboundActionStatus::Sent,
                    'external_id' => $response->json('event_id') ?? '',
                    'response' => $response->json(),
                    'sent_at' => now(),
                ]);
            } else {
                $action->update([
                    'status' => OutboundActionStatus::Failed,
                    'response' => $response->json() ?? ['error' => $response->body()],
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
        return $channel === 'matrix';
    }
}
