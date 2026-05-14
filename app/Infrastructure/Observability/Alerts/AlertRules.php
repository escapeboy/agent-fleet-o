<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Alerts;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Loads the full rule set from `config/observability.php`.
 *
 * Adding a new rule is a two-step change:
 *   1. Add an env-driven threshold + key under `observability.alerts.thresholds`.
 *   2. Add a corresponding rule entry below.
 *
 * The rule's `metricName` is also the key inside `thresholds`, keeping config
 * and code linked by name.
 */
final class AlertRules
{
    /** @var array<int, AlertRule>|null */
    private ?array $cached = null;

    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @return array<int, AlertRule>
     */
    public function all(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $thresholds = (array) $this->config->get('observability.alerts.thresholds', []);

        $catalogue = [
            'queue_depth' => [
                'severity' => 'warning',
                'description' => 'Total queue depth above threshold — Horizon workers may be over-saturated.',
            ],
            'error_rate_per_minute' => [
                'severity' => 'warning',
                'description' => 'Captured error rate exceeds threshold — production bug or upstream outage.',
            ],
            'p95_llm_latency_ms' => [
                'severity' => 'warning',
                'description' => 'p95 LLM latency above threshold — provider degradation likely.',
            ],
            'stuck_experiments' => [
                'severity' => 'warning',
                'description' => 'Experiment stages stuck above threshold — pipeline locked or worker dead.',
            ],
            'circuit_breaker_open' => [
                'severity' => 'critical',
                'description' => 'AI provider circuit breaker open — calls being rejected.',
            ],
            'phoenix_export_failures_per_minute' => [
                'severity' => 'warning',
                'description' => 'Phoenix OTLP export failure rate above threshold — sidecar likely down. Traces silently dropped.',
            ],
        ];

        $rules = [];
        foreach ($catalogue as $metric => $meta) {
            $threshold = (int) ($thresholds[$metric] ?? 0);
            if ($threshold <= 0) {
                continue; // disabled
            }

            $rules[] = new AlertRule(
                metricName: $metric,
                threshold: $threshold,
                severity: (string) $meta['severity'],
                description: (string) $meta['description'],
            );
        }

        return $this->cached = $rules;
    }
}
