<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\DTOs\AlertSignalDTO;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Log;

/**
 * @deprecated Use DatadogIntegrationDriver for new integrations.
 */
class DatadogAlertConnector implements InputConnectorInterface
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
        private readonly CreateExperimentAction $createExperiment,
        private readonly TransitionExperimentAction $transition,
    ) {}

    /**
     * Ingest a Datadog webhook payload as a signal.
     *
     * Datadog does not provide HMAC signatures. Authentication is done via
     * a secret token embedded in the webhook URL: /api/signals/datadog/{secret}.
     *
     * Config expects:
     *   'payload'                => array  (custom Datadog payload template)
     *   'auto_create_experiment' => bool
     *   'user_id'                => string
     *   'severity_threshold'     => string
     *   'default_tags'           => string[]
     *
     * Expected Datadog custom payload fields:
     *   alert_id, alert_title, alert_status, alert_type, alert_transition, priority,
     *   hostname, tags, link, event_message
     *
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $payload = $config['payload'] ?? [];

        if (empty($payload)) {
            return [];
        }

        $dto = $this->normalize($payload);

        if (! $this->meetsSeverityThreshold($dto->severity, $config['severity_threshold'] ?? 'info')) {
            return [];
        }

        $defaultTags = $config['default_tags'] ?? [];
        $tags = array_values(array_unique(
            array_merge(['datadog', 'alert', $dto->severity, $dto->status], $defaultTags),
        ));

        $signal = $this->ingestAction->execute(
            sourceType: 'datadog',
            sourceIdentifier: $dto->alertId,
            payload: [
                'title' => $dto->title,
                'severity' => $dto->severity,
                'status' => $dto->status,
                'url' => $dto->url,
                'service' => $dto->service,
                'environment' => $dto->environment,
                'platform' => 'datadog',
                'raw' => $dto->rawPayload,
            ],
            tags: $tags,
            sourceNativeId: 'dd:'.$dto->alertId,
        );

        if (! $signal) {
            return [];
        }

        if ($dto->status === 'triggered' && ! empty($config['auto_create_experiment']) && ! empty($config['user_id'])) {
            $this->autoCreateExperiment($signal, $config, $dto);
        }

        return [$signal];
    }

    public function supports(string $driver): bool
    {
        return $driver === 'datadog';
    }

    private function normalize(array $payload): AlertSignalDTO
    {
        // Datadog custom payload template variables
        $alertId = (string) ($payload['alert_id'] ?? uniqid('dd_', true));
        $title = $payload['alert_title'] ?? $payload['event_title'] ?? 'Datadog Alert';
        $alertStatus = strtolower($payload['alert_status'] ?? $payload['alert_transition'] ?? 'triggered');
        $alertType = strtolower($payload['alert_type'] ?? 'error');
        $priority = strtolower($payload['priority'] ?? 'normal');
        $hostname = $payload['hostname'] ?? null;
        $link = $payload['link'] ?? '';

        $status = match (true) {
            str_contains($alertStatus, 'recover') => 'resolved',
            str_contains($alertStatus, 'alert'), str_contains($alertStatus, 'trigger') => 'triggered',
            default => 'triggered',
        };

        $severity = match (true) {
            $priority === 'high' || str_contains($alertType, 'error') => 'high',
            str_contains($alertType, 'warn') => 'warning',
            default => 'info',
        };

        // Try to extract environment from Datadog tags CSV (e.g., "env:production,service:api")
        $ddTags = $payload['tags'] ?? '';
        $environment = null;
        $service = $hostname;

        foreach (explode(',', $ddTags) as $tag) {
            $tag = trim($tag);
            if (str_starts_with($tag, 'env:')) {
                $environment = substr($tag, 4);
            } elseif (str_starts_with($tag, 'service:')) {
                $service = substr($tag, 8);
            }
        }

        return new AlertSignalDTO(
            platform: 'datadog',
            alertId: $alertId,
            title: $title,
            severity: $severity,
            status: $status,
            url: $link,
            service: $service,
            environment: $environment,
            rawPayload: $payload,
        );
    }

    private function meetsSeverityThreshold(string $severity, string $threshold): bool
    {
        $order = ['info' => 0, 'warning' => 1, 'high' => 2, 'critical' => 3];

        return ($order[$severity] ?? 0) >= ($order[$threshold] ?? 0);
    }

    private function autoCreateExperiment(Signal $signal, array $config, AlertSignalDTO $dto): void
    {
        try {
            $title = "[{$dto->severity}] {$dto->title}";

            $experiment = $this->createExperiment->execute(
                userId: $config['user_id'],
                title: $title,
                thesis: "Auto-triggered from Datadog alert: {$dto->title}",
                track: 'debug',
                budgetCapCredits: $config['budget_cap_credits'] ?? 10000,
            );

            $signal->update(['experiment_id' => $experiment->id]);

            $this->transition->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Scoring,
                reason: 'Auto-started from Datadog alert signal',
            );
        } catch (\Throwable $e) {
            Log::error('DatadogAlertConnector: Failed to auto-create experiment', [
                'signal_id' => $signal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
