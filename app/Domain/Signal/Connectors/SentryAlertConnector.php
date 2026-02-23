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

class SentryAlertConnector implements InputConnectorInterface
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
        private readonly CreateExperimentAction $createExperiment,
        private readonly TransitionExperimentAction $transition,
    ) {}

    /**
     * Ingest a Sentry alert webhook as a signal.
     *
     * Config expects:
     *   'payload'                => array
     *   'auto_create_experiment' => bool
     *   'user_id'                => string
     *   'severity_threshold'     => string  'critical'|'high'|'warning'|'info'
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

        $dto = $this->normalize($payload);

        if (! $dto) {
            return [];
        }

        // Filter by severity threshold
        if (! $this->meetsSeverityThreshold($dto->severity, $config['severity_threshold'] ?? 'info')) {
            return [];
        }

        $defaultTags = $config['default_tags'] ?? [];
        $tags = array_values(array_unique(
            array_merge(['sentry', 'alert', $dto->severity, $dto->status], $defaultTags)
        ));

        $signal = $this->ingestAction->execute(
            sourceType: 'sentry',
            sourceIdentifier: $dto->alertId,
            payload: [
                'title' => $dto->title,
                'severity' => $dto->severity,
                'status' => $dto->status,
                'url' => $dto->url,
                'service' => $dto->service,
                'environment' => $dto->environment,
                'platform' => 'sentry',
                'raw' => $dto->rawPayload,
            ],
            tags: $tags,
            sourceNativeId: 'sentry:'.$dto->alertId,
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
        return $driver === 'sentry';
    }

    /**
     * Validate Sentry webhook signature (HMAC-SHA256).
     * Header: Sentry-Hook-Signature (raw hex, no prefix).
     */
    public static function validateSignature(string $rawBody, string $signatureHeader, string $secret): bool
    {
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }

    private function normalize(array $payload): ?AlertSignalDTO
    {
        $action = $payload['action'] ?? '';
        $resource = $payload['data'] ?? [];

        // Sentry sends event_alert and issue resources
        $event = $resource['event'] ?? null;
        $issue = $resource['issue'] ?? null;

        if ($event) {
            return new AlertSignalDTO(
                platform: 'sentry',
                alertId: $event['id'] ?? uniqid('sentry_', true),
                title: $event['title'] ?? 'Sentry Alert',
                severity: $this->mapSentryLevel($event['level'] ?? 'error'),
                status: $action === 'triggered' ? 'triggered' : 'resolved',
                url: $event['url'] ?? '',
                service: $event['project'] ?? null,
                environment: $this->extractEnvironment($event['tags'] ?? []),
                rawPayload: $payload,
            );
        }

        if ($issue) {
            return new AlertSignalDTO(
                platform: 'sentry',
                alertId: $issue['id'] ?? uniqid('sentry_', true),
                title: $issue['title'] ?? 'Sentry Issue',
                severity: $this->mapSentryLevel($issue['level'] ?? 'error'),
                status: match ($action) {
                    'created' => 'triggered',
                    'resolved' => 'resolved',
                    default => 'triggered',
                },
                url: $issue['permalink'] ?? '',
                service: $issue['project']['slug'] ?? null,
                environment: null,
                rawPayload: $payload,
            );
        }

        Log::debug('SentryAlertConnector: Unrecognized payload structure', ['payload' => $payload]);

        return null;
    }

    private function mapSentryLevel(string $level): string
    {
        return match ($level) {
            'fatal' => 'critical',
            'error' => 'high',
            'warning' => 'warning',
            'info', 'debug' => 'info',
            default => 'high',
        };
    }

    private function extractEnvironment(array $tags): ?string
    {
        foreach ($tags as $tag) {
            if (($tag[0] ?? '') === 'environment') {
                return $tag[1] ?? null;
            }
        }

        return null;
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
                thesis: "Auto-triggered from Sentry alert: {$dto->title}",
                track: 'debug',
                budgetCapCredits: $config['budget_cap_credits'] ?? 10000,
            );

            $signal->update(['experiment_id' => $experiment->id]);

            $this->transition->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Scoring,
                reason: 'Auto-started from Sentry alert signal',
            );
        } catch (\Throwable $e) {
            Log::error('SentryAlertConnector: Failed to auto-create experiment', [
                'signal_id' => $signal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
