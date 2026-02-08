@props(['status'])

@php
$colors = match(true) {
    in_array($status, ['active', 'completed', 'approved', 'sent', 'closed']) => 'bg-green-100 text-green-800',
    in_array($status, ['running', 'executing', 'sending', 'scoring', 'planning', 'building', 'evaluating', 'iterating', 'collecting_metrics']) => 'bg-blue-100 text-blue-800',
    in_array($status, ['pending', 'draft', 'signal_detected', 'queued', 'pending_approval']) => 'bg-gray-100 text-gray-800',
    in_array($status, ['paused', 'degraded', 'half_open', 'awaiting_approval']) => 'bg-yellow-100 text-yellow-800',
    in_array($status, ['failed', 'killed', 'disabled', 'error', 'bounced', 'scoring_failed', 'planning_failed', 'building_failed', 'execution_failed', 'open']) => 'bg-red-100 text-red-800',
    in_array($status, ['rejected', 'discarded', 'expired', 'cancelled', 'offline']) => 'bg-gray-200 text-gray-600',
    default => 'bg-gray-100 text-gray-800',
};
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {$colors}"]) }}>
    {{ str_replace('_', ' ', ucfirst($status)) }}
</span>
