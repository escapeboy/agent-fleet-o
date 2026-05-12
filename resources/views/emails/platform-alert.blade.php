<x-mail::message>
# FleetQ Platform Alert

A platform-level alert has been triggered.

- **Metric:** `{{ $rule->metricName }}`
- **Severity:** **{{ strtoupper($rule->severity) }}**
- **Current value:** {{ $currentValue }}
- **Threshold:** {{ $rule->threshold }}
- **Triggered at:** {{ $triggeredAt->toIso8601String() }}

> {{ $rule->description }}

@if($grafanaUrl !== '')
<x-mail::button :url="$grafanaUrl">Open Grafana</x-mail::button>
@endif

The alert will not re-fire within the configured cooldown window. To silence, update
`config/observability.php` thresholds or set the corresponding env var to `0`.

— FleetQ Platform · {{ $appUrl }}
</x-mail::message>
