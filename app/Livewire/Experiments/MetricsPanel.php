<?php

namespace App\Livewire\Experiments;

use App\Domain\Experiment\Models\Experiment;
use Livewire\Component;

class MetricsPanel extends Component
{
    public Experiment $experiment;

    public function render()
    {
        $metrics = $this->experiment->metrics()
            ->orderBy('occurred_at', 'desc')
            ->get();

        // Pipeline timing: aggregate state_duration metrics across all iterations
        $pipelineTimings = $metrics
            ->where('type', 'state_duration')
            ->filter(fn ($m) => abs($m->value) > 0)
            ->groupBy(fn ($m) => $m->metadata['from_state'] ?? 'unknown')
            ->map(fn ($group) => abs($group->sum('value')));

        // Define stage display order
        $stageOrder = [
            'signal_detected', 'scoring', 'planning', 'building',
            'building_failed', 'awaiting_approval', 'approved', 'executing',
            'collecting_metrics', 'evaluating', 'iterating', 'paused',
        ];

        $orderedTimings = collect($stageOrder)
            ->filter(fn ($stage) => $pipelineTimings->has($stage))
            ->mapWithKeys(fn ($stage) => [$stage => $pipelineTimings[$stage]]);

        // Add any stages not in the predefined order
        $remainingTimings = $pipelineTimings->diffKeys($orderedTimings);
        $orderedTimings = $orderedTimings->merge($remainingTimings);

        $totalPipelineSeconds = $orderedTimings->sum();
        $maxStageSeconds = $orderedTimings->max() ?: 1;

        // Outbound metrics
        $deliveries = $metrics->where('type', 'delivery');
        $delivered = $deliveries->where('value', '>=', 1.0)->count();
        $totalOutbound = $deliveries->count();
        $deliveryRate = $totalOutbound > 0 ? round(($delivered / $totalOutbound) * 100) : 0;

        $engagement = $metrics->where('type', 'engagement');
        $avgEngagement = $engagement->isNotEmpty() ? round($engagement->avg('value'), 2) : null;

        $clicks = $metrics->where('type', 'click')->count();
        $opens = $metrics->where('type', 'open')->count();

        $payments = $metrics->where('type', 'payment');
        $totalRevenue = $payments->sum('value') / 100; // cents to EUR
        $paymentCount = $payments->count();

        $hasOutboundMetrics = $deliveries->isNotEmpty() || $engagement->isNotEmpty()
            || $clicks > 0 || $opens > 0 || $payments->isNotEmpty();

        // Recent non-duration activity
        $recentActivity = $metrics->where('type', '!=', 'state_duration')->take(20);

        return view('livewire.experiments.metrics-panel', [
            'pipelineTimings' => $orderedTimings,
            'totalPipelineSeconds' => $totalPipelineSeconds,
            'maxStageSeconds' => $maxStageSeconds,
            'delivered' => $delivered,
            'totalOutbound' => $totalOutbound,
            'deliveryRate' => $deliveryRate,
            'avgEngagement' => $avgEngagement,
            'clicks' => $clicks,
            'opens' => $opens,
            'totalRevenue' => $totalRevenue,
            'paymentCount' => $paymentCount,
            'hasOutboundMetrics' => $hasOutboundMetrics,
            'recentActivity' => $recentActivity,
        ]);
    }

    public function formatDuration(float $seconds): string
    {
        $seconds = abs((int) $seconds);

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        if ($seconds < 3600) {
            $m = floor($seconds / 60);
            $s = $seconds % 60;

            return $s > 0 ? "{$m}m {$s}s" : "{$m}m";
        }

        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);

        return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    }
}
