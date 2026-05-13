<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Telemetry\Sentry;

use App\Infrastructure\Telemetry\Sentry\SentryContext;
use PHPUnit\Framework\Attributes\Test;
use Sentry\Event;
use Sentry\State\Scope;
use Tests\TestCase;

class SentryContextTest extends TestCase
{
    #[Test]
    public function it_sets_known_tags_on_scope(): void
    {
        $context = new SentryContext;
        $scope = new Scope;

        $context->apply($scope, [
            'team_id' => 'team-uuid',
            'experiment_id' => 'exp-uuid',
            'agent_id' => 'agent-uuid',
            'sub_program' => 'experiment.stage',
            'experiment_stage' => 'building',
        ]);

        $event = Event::createEvent();
        $scope->applyToEvent($event);

        $tags = $event->getTags();
        $this->assertSame('team-uuid', $tags['team_id'] ?? null);
        $this->assertSame('exp-uuid', $tags['experiment_id'] ?? null);
        $this->assertSame('agent-uuid', $tags['agent_id'] ?? null);
        $this->assertSame('experiment.stage', $tags['sub_program'] ?? null);
        $this->assertSame('building', $tags['experiment_stage'] ?? null);
    }

    #[Test]
    public function it_skips_null_and_empty_values(): void
    {
        $context = new SentryContext;
        $scope = new Scope;

        $context->apply($scope, [
            'team_id' => 'team-uuid',
            'experiment_id' => null,
            'agent_id' => '',
            'project_run_id' => [],
        ]);

        $event = Event::createEvent();
        $scope->applyToEvent($event);

        $tags = $event->getTags();
        $this->assertSame('team-uuid', $tags['team_id'] ?? null);
        $this->assertArrayNotHasKey('experiment_id', $tags);
        $this->assertArrayNotHasKey('agent_id', $tags);
        $this->assertArrayNotHasKey('project_run_id', $tags);
    }

    #[Test]
    public function it_exposes_only_whitelisted_keys_via_tags_for(): void
    {
        $context = new SentryContext;

        $tags = $context->tagsFor([
            'team_id' => 'team-uuid',
            'experiment_id' => 'exp-uuid',
            'random_field' => 'not-a-tag',
            'agent_id' => '',
        ]);

        $this->assertArrayHasKey('team_id', $tags);
        $this->assertArrayHasKey('experiment_id', $tags);
        $this->assertArrayNotHasKey('random_field', $tags);
        $this->assertArrayNotHasKey('agent_id', $tags);
    }

    #[Test]
    public function it_casts_non_string_scalars_to_string(): void
    {
        $context = new SentryContext;
        $scope = new Scope;

        $context->apply($scope, [
            'team_id' => 12345,
            'experiment_id' => 'exp-uuid',
        ]);

        $event = Event::createEvent();
        $scope->applyToEvent($event);

        $tags = $event->getTags();
        $this->assertSame('12345', $tags['team_id'] ?? null);
    }
}
