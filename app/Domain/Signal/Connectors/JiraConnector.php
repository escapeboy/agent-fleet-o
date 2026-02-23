<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Log;

class JiraConnector implements InputConnectorInterface
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
        private readonly CreateExperimentAction $createExperiment,
        private readonly TransitionExperimentAction $transition,
    ) {}

    /**
     * Ingest a Jira webhook payload as a signal.
     *
     * Config expects:
     *   'payload'               => array   (raw Jira webhook body)
     *   'auto_create_experiment' => bool
     *   'user_id'               => string
     *   'filter_events'         => string[] e.g. ['jira:issue_created']
     *   'filter_projects'       => string[] e.g. ['PROJ', 'ENG']
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

        $webhookEvent = $payload['webhookEvent'] ?? '';
        $issue = $payload['issue'] ?? null;

        if (! $issue) {
            return [];
        }

        // Filter by event type
        $filterEvents = $config['filter_events'] ?? ['jira:issue_created'];
        if (! empty($filterEvents) && ! in_array($webhookEvent, $filterEvents, true)) {
            return [];
        }

        // Filter by project key
        $filterProjects = $config['filter_projects'] ?? [];
        $projectKey = $issue['fields']['project']['key'] ?? null;
        if (! empty($filterProjects) && ! in_array($projectKey, $filterProjects, true)) {
            return [];
        }

        $issueKey = $issue['key'] ?? 'unknown';
        $fields = $issue['fields'] ?? [];
        $title = $fields['summary'] ?? 'Untitled Issue';
        $description = $this->extractDescription($fields['description'] ?? null);
        $url = $issue['self'] ?? '';
        $issueLabels = $fields['labels'] ?? [];
        $defaultTags = $config['default_tags'] ?? [];

        $signalPayload = [
            'title' => $title,
            'content' => $description,
            'url' => $url,
            'issue_key' => $issueKey,
            'project' => $projectKey,
            'status' => $fields['status']['name'] ?? null,
            'priority' => $fields['priority']['name'] ?? null,
            'issue_type' => $fields['issuetype']['name'] ?? null,
            'assignee' => $fields['assignee']['displayName'] ?? null,
            'reporter' => $fields['reporter']['displayName'] ?? null,
            'labels' => $issueLabels,
            'event_type' => $webhookEvent,
            'created_at' => $fields['created'] ?? null,
        ];

        $tags = array_values(array_unique(
            array_merge(['jira', 'ticket'], $issueLabels, $defaultTags)
        ));

        $signal = $this->ingestAction->execute(
            sourceType: 'jira',
            sourceIdentifier: $issueKey,
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
        return $driver === 'jira';
    }

    /**
     * Validate Jira webhook signature (HMAC-SHA256).
     * Header format: "sha256=<hex>"
     */
    public static function validateSignature(string $rawBody, string $signatureHeader, string $secret): bool
    {
        // Strip "sha256=" prefix if present
        $signature = str_starts_with($signatureHeader, 'sha256=')
            ? substr($signatureHeader, 7)
            : $signatureHeader;

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Extract plain text from Jira's Atlassian Document Format (ADF) or legacy string.
     */
    private function extractDescription(mixed $description): string
    {
        if (is_string($description)) {
            return $description;
        }

        if (is_array($description) && isset($description['content'])) {
            return $this->extractAdfText($description);
        }

        return '';
    }

    private function extractAdfText(array $node): string
    {
        $text = '';

        if (isset($node['text'])) {
            $text .= $node['text'];
        }

        foreach ($node['content'] ?? [] as $child) {
            $text .= $this->extractAdfText($child);
        }

        return $text;
    }

    private function autoCreateExperiment(Signal $signal, array $config, string $title): void
    {
        try {
            $experiment = $this->createExperiment->execute(
                userId: $config['user_id'],
                title: $title,
                thesis: "Auto-triggered from Jira issue: {$title}",
                track: 'analysis',
                budgetCapCredits: $config['budget_cap_credits'] ?? 10000,
            );

            $signal->update(['experiment_id' => $experiment->id]);

            $this->transition->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Scoring,
                reason: 'Auto-started from Jira issue signal',
            );
        } catch (\Throwable $e) {
            Log::error('JiraConnector: Failed to auto-create experiment', [
                'signal_id' => $signal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
