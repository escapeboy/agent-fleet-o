<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Log;

class GitHubIssuesConnector implements InputConnectorInterface
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
        private readonly CreateExperimentAction $createExperiment,
        private readonly TransitionExperimentAction $transition,
    ) {}

    /**
     * Ingest a GitHub Issues webhook payload as a signal.
     *
     * Config expects:
     *   'payload'               => array   (raw GitHub webhook body)
     *   'auto_create_experiment' => bool   (optional, default false)
     *   'agent_id'              => string  (optional, used when auto-creating experiment)
     *   'user_id'               => string  (required when auto_create_experiment = true)
     *   'filter_events'         => string[] e.g. ['opened', 'reopened']
     *   'filter_labels'         => string[] filter by label name, empty = all
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

        $action = $payload['action'] ?? '';
        $issue = $payload['issue'] ?? null;

        if (! $issue) {
            return [];
        }

        // Filter by event action
        $filterEvents = $config['filter_events'] ?? ['opened', 'reopened'];
        if (! empty($filterEvents) && ! in_array($action, $filterEvents, true)) {
            return [];
        }

        // Filter by label
        $filterLabels = $config['filter_labels'] ?? [];
        if (! empty($filterLabels)) {
            $issueLabels = array_column($issue['labels'] ?? [], 'name');
            if (empty(array_intersect($filterLabels, $issueLabels))) {
                return [];
            }
        }

        $issueNumber = (string) ($issue['number'] ?? 'unknown');
        $repo = $payload['repository']['full_name'] ?? 'unknown';
        $title = $issue['title'] ?? 'Untitled Issue';
        $body = $issue['body'] ?? '';
        $url = $issue['html_url'] ?? '';
        $issueLabels = array_column($issue['labels'] ?? [], 'name');
        $defaultTags = $config['default_tags'] ?? [];

        $signalPayload = [
            'title' => $title,
            'content' => $body,
            'url' => $url,
            'issue_number' => $issue['number'] ?? null,
            'repo' => $repo,
            'state' => $issue['state'] ?? 'open',
            'author' => $issue['user']['login'] ?? null,
            'labels' => $issueLabels,
            'event_type' => $action,
            'created_at' => $issue['created_at'] ?? null,
        ];

        $tags = array_values(array_unique(
            array_merge(['github_issues', 'ticket'], $issueLabels, $defaultTags)
        ));

        $signal = $this->ingestAction->execute(
            sourceType: 'github_issues',
            sourceIdentifier: "{$repo}#{$issueNumber}",
            payload: $signalPayload,
            tags: $tags,
        );

        if (! $signal) {
            return [];
        }

        // Auto-create experiment if configured
        if (! empty($config['auto_create_experiment']) && ! empty($config['user_id'])) {
            $this->autoCreateExperiment($signal, $config, $title);
        }

        return [$signal];
    }

    public function supports(string $driver): bool
    {
        return $driver === 'github_issues';
    }

    /**
     * Validate GitHub webhook signature (HMAC-SHA256).
     * Header format: "sha256=<hex>"
     */
    public static function validateSignature(string $rawBody, string $signatureHeader, string $secret): bool
    {
        if (! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $signature = substr($signatureHeader, 7);
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    private function autoCreateExperiment(Signal $signal, array $config, string $title): void
    {
        try {
            $experiment = $this->createExperiment->execute(
                userId: $config['user_id'],
                title: $title,
                thesis: "Auto-triggered from GitHub issue: {$title}",
                track: 'analysis',
                budgetCapCredits: $config['budget_cap_credits'] ?? 10000,
            );

            $signal->update(['experiment_id' => $experiment->id]);

            $this->transition->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Scoring,
                reason: 'Auto-started from GitHub issue signal',
            );
        } catch (\Throwable $e) {
            Log::error('GitHubIssuesConnector: Failed to auto-create experiment', [
                'signal_id' => $signal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
