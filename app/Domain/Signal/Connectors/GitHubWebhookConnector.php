<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;

/**
 * GitHub webhook connector supporting multiple event types:
 * issues, pull_request, push, workflow_run, release.
 *
 * Config expects:
 *   'payload'       => array   (raw GitHub webhook body)
 *   'event'         => string  (X-GitHub-Event header value)
 *   'filter_events' => string[] optional — e.g. ['pull_request', 'push']
 *   'default_tags'  => string[]
 */
class GitHubWebhookConnector implements InputConnectorInterface
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    /**
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $payload = $config['payload'] ?? [];
        $event = $config['event'] ?? '';

        if (empty($payload) || empty($event)) {
            return [];
        }

        $filterEvents = $config['filter_events'] ?? [];
        if (! empty($filterEvents) && ! in_array($event, $filterEvents, true)) {
            return [];
        }

        $signal = match ($event) {
            'issues' => $this->handleIssues($payload, $config),
            'pull_request' => $this->handlePullRequest($payload, $config),
            'push' => $this->handlePush($payload, $config),
            'workflow_run' => $this->handleWorkflowRun($payload, $config),
            'release' => $this->handleRelease($payload, $config),
            default => null,
        };

        return $signal ? [$signal] : [];
    }

    public function supports(string $driver): bool
    {
        return $driver === 'github';
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

    private function handleIssues(array $payload, array $config): ?Signal
    {
        $action = $payload['action'] ?? '';
        $issue = $payload['issue'] ?? null;

        if (! $issue) {
            return null;
        }

        $filterActions = $config['filter_actions'] ?? ['opened', 'reopened'];
        if (! empty($filterActions) && ! in_array($action, $filterActions, true)) {
            return null;
        }

        $filterLabels = $config['filter_labels'] ?? [];
        if (! empty($filterLabels)) {
            $issueLabels = array_column($issue['labels'] ?? [], 'name');
            if (empty(array_intersect($filterLabels, $issueLabels))) {
                return null;
            }
        }

        $repo = $payload['repository']['full_name'] ?? 'unknown';
        $issueNumber = (string) ($issue['number'] ?? 'unknown');
        $issueLabels = array_column($issue['labels'] ?? [], 'name');
        $defaultTags = $config['default_tags'] ?? [];

        $repoNodeId = $payload['repository']['node_id'] ?? '';
        $issueNodeId = $issue['node_id'] ?? '';

        return $this->ingestAction->execute(
            sourceType: 'github',
            sourceIdentifier: "{$repo}#{$issueNumber}",
            sourceNativeId: "issues.{$action}.{$repoNodeId}.{$issueNodeId}",
            payload: [
                'event' => 'issues',
                'action' => $action,
                'title' => $issue['title'] ?? '',
                'content' => $issue['body'] ?? '',
                'url' => $issue['html_url'] ?? '',
                'issue_number' => $issue['number'] ?? null,
                'repo' => $repo,
                'state' => $issue['state'] ?? 'open',
                'author' => $issue['user']['login'] ?? null,
                'labels' => $issueLabels,
            ],
            tags: array_values(array_unique(
                array_merge(['github', 'github_issues', 'ticket'], $issueLabels, $defaultTags),
            )),
        );
    }

    private function handlePullRequest(array $payload, array $config): ?Signal
    {
        $action = $payload['action'] ?? '';
        $pr = $payload['pull_request'] ?? null;

        if (! $pr) {
            return null;
        }

        $filterActions = $config['filter_actions'] ?? ['opened', 'closed', 'merged'];
        if (! empty($filterActions) && ! in_array($action, $filterActions, true)) {
            return null;
        }

        // For 'closed' action, only signal if merged (unless filter explicitly includes 'closed')
        $merged = (bool) ($pr['merged'] ?? false);
        if ($action === 'closed' && ! $merged && in_array('merged', $filterActions, true) && ! in_array('closed', $filterActions, true)) {
            return null;
        }

        $repo = $payload['repository']['full_name'] ?? 'unknown';
        $prNumber = (string) ($pr['number'] ?? 'unknown');
        $defaultTags = $config['default_tags'] ?? [];
        $eventAction = ($action === 'closed' && $merged) ? 'merged' : $action;

        $prNodeId = $pr['node_id'] ?? '';

        return $this->ingestAction->execute(
            sourceType: 'github',
            sourceIdentifier: "{$repo}#PR-{$prNumber}",
            sourceNativeId: "pull_request.{$action}.{$prNodeId}",
            payload: [
                'event' => 'pull_request',
                'action' => $eventAction,
                'title' => $pr['title'] ?? '',
                'content' => $pr['body'] ?? '',
                'url' => $pr['html_url'] ?? '',
                'pr_number' => $pr['number'] ?? null,
                'repo' => $repo,
                'state' => $pr['state'] ?? '',
                'merged' => $merged,
                'author' => $pr['user']['login'] ?? null,
                'base_branch' => $pr['base']['ref'] ?? null,
                'head_branch' => $pr['head']['ref'] ?? null,
            ],
            tags: array_values(array_unique(
                array_merge(['github', 'pull_request', $eventAction], $defaultTags),
            )),
        );
    }

    private function handlePush(array $payload, array $config): ?Signal
    {
        $ref = $payload['ref'] ?? '';
        $repo = $payload['repository']['full_name'] ?? 'unknown';
        $pusher = $payload['pusher']['name'] ?? null;
        $commits = $payload['commits'] ?? [];
        $defaultTags = $config['default_tags'] ?? [];

        // Filter by branch if configured
        $filterBranches = $config['filter_branches'] ?? [];
        if (! empty($filterBranches)) {
            $branch = str_replace('refs/heads/', '', $ref);
            if (! in_array($branch, $filterBranches, true)) {
                return null;
            }
        }

        // Skip tag pushes unless explicitly enabled
        if (str_starts_with($ref, 'refs/tags/') && ! ($config['include_tags'] ?? false)) {
            return null;
        }

        $commitCount = count($commits);
        if ($commitCount === 0) {
            return null;
        }

        $headCommit = $payload['head_commit'] ?? end($commits) ?: [];

        $afterSha = $payload['after'] ?? uniqid();

        return $this->ingestAction->execute(
            sourceType: 'github',
            sourceIdentifier: "{$repo}:{$ref}",
            sourceNativeId: "push.{$afterSha}",
            payload: [
                'event' => 'push',
                'ref' => $ref,
                'branch' => str_replace('refs/heads/', '', $ref),
                'repo' => $repo,
                'pusher' => $pusher,
                'commit_count' => $commitCount,
                'head_commit_id' => $headCommit['id'] ?? null,
                'head_commit_message' => $headCommit['message'] ?? null,
                'head_commit_author' => $headCommit['author']['name'] ?? null,
                'compare_url' => $payload['compare'] ?? null,
                'commits' => array_slice(array_map(fn ($c) => [
                    'id' => $c['id'] ?? null,
                    'message' => $c['message'] ?? null,
                    'author' => $c['author']['name'] ?? null,
                    'url' => $c['url'] ?? null,
                ], $commits), 0, 10),
            ],
            tags: array_values(array_unique(
                array_merge(['github', 'push'], $defaultTags),
            )),
        );
    }

    private function handleWorkflowRun(array $payload, array $config): ?Signal
    {
        $action = $payload['action'] ?? '';

        if ($action !== 'completed') {
            return null;
        }

        $run = $payload['workflow_run'] ?? null;

        if (! $run) {
            return null;
        }

        $conclusion = $run['conclusion'] ?? '';
        $filterConclusions = $config['filter_conclusions'] ?? ['failure', 'timed_out'];

        if (! empty($filterConclusions) && ! in_array($conclusion, $filterConclusions, true)) {
            return null;
        }

        $repo = $payload['repository']['full_name'] ?? 'unknown';
        $defaultTags = $config['default_tags'] ?? [];

        $workflowName = $run['name'] ?? 'unknown';
        $runNodeId = $run['node_id'] ?? $run['id'] ?? '';

        return $this->ingestAction->execute(
            sourceType: 'github',
            sourceIdentifier: "{$repo}/workflow/{$workflowName}",
            sourceNativeId: "workflow_run.completed.{$runNodeId}",
            payload: [
                'event' => 'workflow_run',
                'action' => 'completed',
                'workflow_name' => $run['name'] ?? null,
                'conclusion' => $conclusion,
                'status' => $run['status'] ?? null,
                'repo' => $repo,
                'branch' => $run['head_branch'] ?? null,
                'run_number' => $run['run_number'] ?? null,
                'run_url' => $run['html_url'] ?? null,
                'actor' => $run['actor']['login'] ?? null,
                'commit_sha' => $run['head_sha'] ?? null,
            ],
            tags: array_values(array_unique(
                array_merge(['github', 'workflow_run', $conclusion], $defaultTags),
            )),
        );
    }

    private function handleRelease(array $payload, array $config): ?Signal
    {
        $action = $payload['action'] ?? '';

        if ($action !== 'published') {
            return null;
        }

        $release = $payload['release'] ?? null;

        if (! $release) {
            return null;
        }

        $repo = $payload['repository']['full_name'] ?? 'unknown';
        $defaultTags = $config['default_tags'] ?? [];
        $releaseTag = $release['tag_name'] ?? 'unknown';
        $releaseNodeId = $release['node_id'] ?? $release['id'] ?? '';

        return $this->ingestAction->execute(
            sourceType: 'github',
            sourceIdentifier: "{$repo}/release/{$releaseTag}",
            sourceNativeId: "release.published.{$releaseNodeId}",
            payload: [
                'event' => 'release',
                'action' => 'published',
                'tag_name' => $release['tag_name'] ?? null,
                'name' => $release['name'] ?? null,
                'body' => $release['body'] ?? null,
                'url' => $release['html_url'] ?? null,
                'repo' => $repo,
                'author' => $release['author']['login'] ?? null,
                'prerelease' => (bool) ($release['prerelease'] ?? false),
                'published_at' => $release['published_at'] ?? null,
            ],
            tags: array_values(array_unique(
                array_merge(['github', 'release'], $defaultTags),
            )),
        );
    }
}
