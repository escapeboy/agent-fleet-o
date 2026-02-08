<?php

namespace App\Domain\Outbound\Connectors;

use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DummyConnector implements OutboundConnectorInterface
{
    public function send(OutboundProposal $proposal): OutboundAction
    {
        $idempotencyKey = hash('xxh128', "dummy|{$proposal->id}");

        // Check for existing action with same idempotency key
        $existing = OutboundAction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        Log::info('DummyConnector: Simulating send', [
            'proposal_id' => $proposal->id,
            'channel' => $proposal->channel->value,
            'target' => $proposal->target,
        ]);

        return OutboundAction::create([
            'outbound_proposal_id' => $proposal->id,
            'status' => OutboundActionStatus::Sent,
            'external_id' => 'dummy-' . Str::uuid()->toString(),
            'response' => ['simulated' => true],
            'idempotency_key' => $idempotencyKey,
            'retry_count' => 0,
            'sent_at' => now(),
        ]);
    }

    public function supports(string $channel): bool
    {
        return true; // Supports all channels as a fallback
    }
}
