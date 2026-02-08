<?php

namespace App\Domain\Metrics\Actions;

use App\Domain\Metrics\Models\Metric;
use App\Domain\Outbound\Models\OutboundAction;
use Illuminate\Support\Facades\Log;

class AttributeRevenueAction
{
    /**
     * Attribute a Stripe payment to an experiment via UTM parameters.
     *
     * Expects metadata from Stripe with: experiment_id, outbound_action_id (optional)
     */
    public function execute(
        string $experimentId,
        float $amountCents,
        string $currency,
        string $stripePaymentId,
        ?string $outboundActionId = null,
        array $metadata = [],
    ): Metric {
        // Validate the outbound action belongs to the experiment
        if ($outboundActionId) {
            $action = OutboundAction::with('outboundProposal')
                ->where('id', $outboundActionId)
                ->first();

            if ($action && $action->outboundProposal?->experiment_id !== $experimentId) {
                Log::warning('AttributeRevenueAction: outbound action does not belong to experiment', [
                    'experiment_id' => $experimentId,
                    'outbound_action_id' => $outboundActionId,
                ]);
                $outboundActionId = null;
            }
        }

        $metric = Metric::create([
            'experiment_id' => $experimentId,
            'outbound_action_id' => $outboundActionId,
            'type' => 'payment',
            'value' => $amountCents,
            'source' => 'stripe',
            'metadata' => array_filter([
                'stripe_payment_id' => $stripePaymentId,
                'currency' => $currency,
                'amount_cents' => $amountCents,
                'utm_source' => $metadata['utm_source'] ?? null,
                'utm_medium' => $metadata['utm_medium'] ?? null,
                'utm_campaign' => $metadata['utm_campaign'] ?? null,
            ]),
            'occurred_at' => now(),
            'recorded_at' => now(),
        ]);

        Log::info('Revenue attributed', [
            'metric_id' => $metric->id,
            'experiment_id' => $experimentId,
            'amount_cents' => $amountCents,
            'stripe_payment_id' => $stripePaymentId,
        ]);

        return $metric;
    }
}
