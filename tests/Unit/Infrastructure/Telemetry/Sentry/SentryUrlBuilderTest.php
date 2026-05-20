<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Telemetry\Sentry;

use App\Infrastructure\Telemetry\Sentry\SentryUrlBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SentryUrlBuilderTest extends TestCase
{
    #[Test]
    public function it_builds_direct_event_url_when_slugs_and_event_id_present(): void
    {
        config()->set('observability.sentry.org_slug', 'fleetq');
        config()->set('observability.sentry.project_slug', 'platform');
        config()->set(
            'observability.sentry.event_url_template',
            'https://sentry.io/organizations/{org}/projects/{project}/events/{event_id}/',
        );

        $builder = app(SentryUrlBuilder::class);

        $url = $builder->fromMetadata([
            'sentry_event_id' => 'abc123',
            'tags' => ['experiment_id' => 'exp-1'],
        ]);

        $this->assertSame(
            'https://sentry.io/organizations/fleetq/projects/platform/events/abc123/',
            $url,
        );
    }

    #[Test]
    public function it_falls_back_to_issue_search_when_event_id_missing(): void
    {
        config()->set('observability.sentry.org_slug', 'fleetq');
        config()->set('observability.sentry.project_slug', 'platform');
        config()->set(
            'observability.sentry.issue_search_url_template',
            'https://sentry.io/organizations/{org}/issues/?query={query}',
        );

        $builder = app(SentryUrlBuilder::class);

        $url = $builder->fromMetadata([
            'tags' => ['experiment_id' => 'exp-uuid-123'],
        ]);

        $this->assertNotNull($url);
        $this->assertStringContainsString('experiment_id', urldecode($url));
        $this->assertStringContainsString('exp-uuid-123', urldecode($url));
    }

    #[Test]
    public function it_returns_null_when_org_slug_is_empty(): void
    {
        config()->set('observability.sentry.org_slug', '');
        config()->set('observability.sentry.project_slug', 'platform');

        $builder = app(SentryUrlBuilder::class);

        $url = $builder->fromMetadata([
            'sentry_event_id' => 'abc',
            'tags' => ['experiment_id' => 'exp'],
        ]);

        $this->assertNull($url);
    }

    #[Test]
    public function it_returns_null_for_empty_metadata(): void
    {
        config()->set('observability.sentry.org_slug', 'fleetq');

        $builder = app(SentryUrlBuilder::class);

        $this->assertNull($builder->fromMetadata(null));
        $this->assertNull($builder->fromMetadata([]));
    }

    #[Test]
    public function it_prefers_most_specific_tag_for_search_query(): void
    {
        config()->set('observability.sentry.org_slug', 'fleetq');
        config()->set('observability.sentry.project_slug', '');
        config()->set(
            'observability.sentry.issue_search_url_template',
            'https://sentry.io/organizations/{org}/issues/?query={query}',
        );

        $builder = app(SentryUrlBuilder::class);

        $url = $builder->fromMetadata([
            'tags' => [
                'team_id' => 'team-1',
                'experiment_id' => 'exp-1',
                'agent_id' => 'agent-1',
            ],
        ]);

        // experiment_id > team_id, agent_id in preference order
        $this->assertStringContainsString('experiment_id%3Aexp-1', $url);
    }
}
