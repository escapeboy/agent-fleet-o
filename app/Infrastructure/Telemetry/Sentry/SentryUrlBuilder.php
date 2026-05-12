<?php

declare(strict_types=1);

namespace App\Infrastructure\Telemetry\Sentry;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Composes Sentry deep-link URLs for the admin UI.
 *
 * Two strategies, in priority order:
 *   1. If `event_id` is known AND project_slug is configured → direct event URL
 *      (most precise, opens the exact event view).
 *   2. Else if any tag-based query is buildable → issue search URL filtered by
 *      tag(s) (still useful — bounces the operator to the matching issue).
 *   3. Else returns null. Callers should hide the deep-link UI.
 *
 * When `org_slug` is missing entirely, always returns null — there's no
 * sensible URL to compose.
 */
final class SentryUrlBuilder
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Build a deep link from an `error_metadata` payload (as persisted by
     * SentryEventCapturer + the caller).
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function fromMetadata(?array $metadata): ?string
    {
        if ($metadata === null || $metadata === []) {
            return null;
        }

        $eventId = $metadata['sentry_event_id'] ?? null;
        if (is_string($eventId) && $eventId !== '') {
            $direct = $this->buildEventUrl($eventId);
            if ($direct !== null) {
                return $direct;
            }
        }

        $tags = is_array($metadata['tags'] ?? null) ? $metadata['tags'] : [];
        $query = $this->composeQueryFromTags($tags);
        if ($query !== null) {
            return $this->buildSearchUrl($query);
        }

        return null;
    }

    /**
     * Build a search URL from an arbitrary tag set (e.g. for a per-team
     * "errors in this team" link).
     *
     * @param  array<string, string>  $tags
     */
    public function searchForTags(array $tags): ?string
    {
        $query = $this->composeQueryFromTags($tags);
        if ($query === null) {
            return null;
        }

        return $this->buildSearchUrl($query);
    }

    private function buildEventUrl(string $eventId): ?string
    {
        $org = (string) $this->config->get('observability.sentry.org_slug', '');
        $project = (string) $this->config->get('observability.sentry.project_slug', '');
        if ($org === '' || $project === '') {
            return null;
        }

        $template = (string) $this->config->get(
            'observability.sentry.event_url_template',
            'https://sentry.io/organizations/{org}/projects/{project}/events/{event_id}/',
        );

        return strtr($template, [
            '{org}' => rawurlencode($org),
            '{project}' => rawurlencode($project),
            '{event_id}' => rawurlencode($eventId),
        ]);
    }

    private function buildSearchUrl(string $query): ?string
    {
        $org = (string) $this->config->get('observability.sentry.org_slug', '');
        if ($org === '') {
            return null;
        }

        $template = (string) $this->config->get(
            'observability.sentry.issue_search_url_template',
            'https://sentry.io/organizations/{org}/issues/?query={query}',
        );

        return strtr($template, [
            '{org}' => rawurlencode($org),
            '{query}' => rawurlencode($query),
        ]);
    }

    /**
     * Compose a Sentry search query from a tag set. Prefers the most-specific
     * tag for narrowest issue list.
     *
     * Priority: experiment_id > crew_execution_id > workflow_node_id > agent_id
     *           > project_run_id > team_id.
     *
     * @param  array<string, string>  $tags
     */
    private function composeQueryFromTags(array $tags): ?string
    {
        $preferenceOrder = [
            'experiment_id',
            'crew_execution_id',
            'workflow_node_id',
            'agent_id',
            'project_run_id',
            'integration_id',
            'team_id',
        ];

        foreach ($preferenceOrder as $key) {
            if (isset($tags[$key]) && $tags[$key] !== '') {
                return "{$key}:".$tags[$key];
            }
        }

        return null;
    }
}
