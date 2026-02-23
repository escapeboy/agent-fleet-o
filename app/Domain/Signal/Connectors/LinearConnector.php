<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Log;

class LinearConnector implements InputConnectorInterface
{
    /**
     * Linear webhooks expire after 5 minutes — reject older payloads.
     */
    private const REPLAY_WINDOW_MS = 5 * 60 * 1000;

    public function __construct(
        private readonly IngestSignalAction $ingestAction,
        private readonly CreateExperimentAction $createExperiment,
        private readonly TransitionExperimentAction $transition,
    ) {}

    /**
     * Ingest a Linear webhook payload as a signal.
     *
     * Config expects:
     *   'payload'               => array
     *   'auto_create_experiment' => bool
     *   'user_id'               => string
     *   'filter_actions'        => string[] e.g. ['create', 'update']
     *   'filter_teams'          => string[] filter by team name
     *   'default_tags'          => string[]
     *
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $payload = $config['payload'] ?? [];

        if (empty($payload)) {
            return [];
        }

        // Replay attack protection
        $webhookTimestamp = $payload['webhookTimestamp'] ?? 0;
        if ($webhookTimestamp > 0) {
            $diffMs = abs(now()->timestamp * 1000 - $webhookTimestamp);
            if ($diffMs > self::REPLAY_WINDOW_MS) {
                Log::warning('LinearConnector: Webhook timestamp too old', [
                    'diff_ms' => $diffMs,
                ]);

                return [];
            }
        }

        $type = $payload['type'] ?? '';
        $action = $payload['action'] ?? '';

        // Only handle Issue events
        if ($type !== 'Issue') {
            return [];
        }

        // Filter by action
        $filterActions = $config['filter_actions'] ?? ['create'];
        if (! empty($filterActions) && ! in_array($action, $filterActions, true)) {
            return [];
        }

        $data = $payload['data'] ?? [];
        $title = $data['title'] ?? 'Untitled Issue';
        $description = $data['description'] ?? '';
        $identifier = $data['identifier'] ?? $data['id'] ?? 'unknown';
        $url = $data['url'] ?? $payload['url'] ?? '';
        $labels = array_column($data['labels'] ?? [], 'name');
        $teamName = $data['team']['name'] ?? null;
        $defaultTags = $config['default_tags'] ?? [];

        // Filter by team
        $filterTeams = $config['filter_teams'] ?? [];
        if (! empty($filterTeams) && $teamName && ! in_array($teamName, $filterTeams, true)) {
            return [];
        }

        $signalPayload = [
            'title' => $title,
            'content' => $description,
            'url' => $url,
            'identifier' => $identifier,
            'team' => $teamName,
            'state' => $data['state']['name'] ?? null,
            'priority' => $data['priority'] ?? null,
            'assignee' => $data['assignee']['name'] ?? null,
            'labels' => $labels,
            'event_type' => $action,
            'actor' => $payload['actor']['name'] ?? null,
            'created_at' => $data['createdAt'] ?? null,
        ];

        $tags = array_values(array_unique(
            array_merge(['linear', 'ticket'], $labels, $defaultTags),
        ));

        $signal = $this->ingestAction->execute(
            sourceType: 'linear',
            sourceIdentifier: $identifier,
            payload: $signalPayload,
            tags: $tags,
        );

        if (! $signal) {
            return [];
        }

        if (! empty($config['auto_create_experiment']) && ! empty($config['user_id'])) {
            $this->autoCreateExperiment($signal, $config, $title);
        }

        return [$signal];
    }

    public function supports(string $driver): bool
    {
        return $driver === 'linear';
    }

    /**
     * Validate Linear webhook signature (HMAC-SHA256).
     * Header format: raw hex (no prefix).
     */
    public static function validateSignature(string $rawBody, string $signatureHeader, string $secret): bool
    {
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }

    private function autoCreateExperiment(Signal $signal, array $config, string $title): void
    {
        try {
            $experiment = $this->createExperiment->execute(
                userId: $config['user_id'],
                title: $title,
                thesis: "Auto-triggered from Linear issue: {$title}",
                track: 'analysis',
                budgetCapCredits: $config['budget_cap_credits'] ?? 10000,
            );

            $signal->update(['experiment_id' => $experiment->id]);

            $this->transition->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Scoring,
                reason: 'Auto-started from Linear issue signal',
            );
        } catch (\Throwable $e) {
            Log::error('LinearConnector: Failed to auto-create experiment', [
                'signal_id' => $signal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
