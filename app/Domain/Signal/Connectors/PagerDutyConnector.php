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

class PagerDutyConnector implements InputConnectorInterface
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
        private readonly CreateExperimentAction $createExperiment,
        private readonly TransitionExperimentAction $transition,
    ) {}

    /**
     * Ingest a PagerDuty v3 webhook payload as a signal.
     *
     * Config expects:
     *   'payload'                => array
     *   'auto_create_experiment' => bool
     *   'user_id'                => string
     *   'severity_threshold'     => string
     *   'default_tags'           => string[]
     *
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $payload = $config['payload'] ?? [];

        if (empty($payload)) {
            return [];
        }

        // PagerDuty v3 sends an array of events
        $events = $payload['events'] ?? [$payload];

        $results = [];

        foreach ($events as $event) {
            $dto = $this->normalize($event);

            if (! $dto) {
                continue;
            }

            if (! $this->meetsSeverityThreshold($dto->severity, $config['severity_threshold'] ?? 'info')) {
                continue;
            }

            $defaultTags = $config['default_tags'] ?? [];
            $tags = array_values(array_unique(
                array_merge(['pagerduty', 'alert', $dto->severity, $dto->status], $defaultTags)
            ));

            $signal = $this->ingestAction->execute(
                sourceType: 'pagerduty',
                sourceIdentifier: $dto->alertId,
                payload: [
                    'title' => $dto->title,
                    'severity' => $dto->severity,
                    'status' => $dto->status,
                    'url' => $dto->url,
                    'service' => $dto->service,
                    'environment' => $dto->environment,
                    'platform' => 'pagerduty',
                    'raw' => $dto->rawPayload,
                ],
                tags: $tags,
                sourceNativeId: 'pd:'.$dto->alertId,
            );

            if (! $signal) {
                continue;
            }

            if ($dto->status === 'triggered' && ! empty($config['auto_create_experiment']) && ! empty($config['user_id'])) {
                $this->autoCreateExperiment($signal, $config, $dto);
            }

            $results[] = $signal;
        }

        return $results;
    }

    public function supports(string $driver): bool
    {
        return $driver === 'pagerduty';
    }

    /**
     * Validate PagerDuty v3 webhook signature (HMAC-SHA256).
     * Header format: "v1=<hex>,v1=<hex>" (supports key rotation — all must be checked).
     */
    public static function validateSignature(string $rawBody, string $signatureHeader, string $secret): bool
    {
        // Header may contain multiple signatures for key rotation: "v1=hash1,v1=hash2"
        $signatures = explode(',', $signatureHeader);
        $expected = hash_hmac('sha256', $rawBody, $secret);

        foreach ($signatures as $sig) {
            [$version, $hash] = array_pad(explode('=', trim($sig), 2), 2, '');

            if ($version === 'v1' && hash_equals($expected, $hash)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(array $event): ?AlertSignalDTO
    {
        $eventType = $event['event_type'] ?? '';
        $data = $event['data'] ?? [];

        if (empty($data)) {
            return null;
        }

        $incidentId = $data['id'] ?? uniqid('pd_', true);
        $title = $data['title'] ?? $data['summary'] ?? 'PagerDuty Incident';
        $status = match (true) {
            str_contains($eventType, 'triggered') => 'triggered',
            str_contains($eventType, 'resolved') => 'resolved',
            str_contains($eventType, 'acknowledged') => 'acknowledged',
            default => 'triggered',
        };

        $urgency = $data['urgency'] ?? 'high';
        $severity = match ($urgency) {
            'high' => 'high',
            'low' => 'warning',
            default => 'high',
        };

        // Check priority for critical override
        $priorityName = strtolower($data['priority']['name'] ?? '');
        if (in_array($priorityName, ['p1', 'p0', 'critical'], true)) {
            $severity = 'critical';
        }

        return new AlertSignalDTO(
            platform: 'pagerduty',
            alertId: $incidentId,
            title: $title,
            severity: $severity,
            status: $status,
            url: $data['html_url'] ?? '',
            service: $data['service']['summary'] ?? $data['service']['name'] ?? null,
            environment: null,
            rawPayload: $event,
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
                thesis: "Auto-triggered from PagerDuty incident: {$dto->title}",
                track: 'debug',
                budgetCapCredits: $config['budget_cap_credits'] ?? 10000,
            );

            $signal->update(['experiment_id' => $experiment->id]);

            $this->transition->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Scoring,
                reason: 'Auto-started from PagerDuty incident signal',
            );
        } catch (\Throwable $e) {
            Log::error('PagerDutyConnector: Failed to auto-create experiment', [
                'signal_id' => $signal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
