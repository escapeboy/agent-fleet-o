<?php

namespace Tests\Unit\Domain\Webhook;

use App\Domain\Webhook\Enums\WebhookEvent;
use App\Domain\Webhook\Models\WebhookEndpoint;
use PHPUnit\Framework\TestCase;

class WebhookTest extends TestCase
{
    public function test_webhook_event_enum_has_expected_cases(): void
    {
        $cases = WebhookEvent::cases();

        $this->assertCount(6, $cases);
        $this->assertEquals('experiment.completed', WebhookEvent::ExperimentCompleted->value);
        $this->assertEquals('experiment.failed', WebhookEvent::ExperimentFailed->value);
        $this->assertEquals('project.run.completed', WebhookEvent::ProjectRunCompleted->value);
        $this->assertEquals('project.run.failed', WebhookEvent::ProjectRunFailed->value);
        $this->assertEquals('approval.pending', WebhookEvent::ApprovalPending->value);
        $this->assertEquals('budget.warning', WebhookEvent::BudgetWarning->value);
    }

    public function test_webhook_event_labels(): void
    {
        $this->assertEquals('Experiment Completed', WebhookEvent::ExperimentCompleted->label());
        $this->assertEquals('Budget Warning', WebhookEvent::BudgetWarning->label());
    }

    public function test_subscribes_to_exact_event(): void
    {
        $endpoint = new WebhookEndpoint;
        $endpoint->events = ['experiment.completed', 'experiment.failed'];

        $this->assertTrue($endpoint->subscribesTo('experiment.completed'));
        $this->assertTrue($endpoint->subscribesTo('experiment.failed'));
        $this->assertFalse($endpoint->subscribesTo('project.run.completed'));
    }

    public function test_subscribes_to_wildcard(): void
    {
        $endpoint = new WebhookEndpoint;
        $endpoint->events = ['*'];

        $this->assertTrue($endpoint->subscribesTo('experiment.completed'));
        $this->assertTrue($endpoint->subscribesTo('project.run.failed'));
        $this->assertTrue($endpoint->subscribesTo('budget.warning'));
    }

    public function test_subscribes_to_empty_events(): void
    {
        $endpoint = new WebhookEndpoint;
        $endpoint->events = [];

        $this->assertFalse($endpoint->subscribesTo('experiment.completed'));
    }

    public function test_subscribes_to_null_events(): void
    {
        $endpoint = new WebhookEndpoint;
        $endpoint->events = null;

        $this->assertFalse($endpoint->subscribesTo('experiment.completed'));
    }

    public function test_model_fillable_attributes(): void
    {
        $endpoint = new WebhookEndpoint;
        $fillable = $endpoint->getFillable();

        $this->assertContains('team_id', $fillable);
        $this->assertContains('name', $fillable);
        $this->assertContains('url', $fillable);
        $this->assertContains('secret', $fillable);
        $this->assertContains('events', $fillable);
        $this->assertContains('is_active', $fillable);
        $this->assertContains('headers', $fillable);
        $this->assertContains('retry_config', $fillable);
    }

    public function test_model_casts_events_as_array(): void
    {
        $endpoint = new WebhookEndpoint;
        $casts = $endpoint->getCasts();

        $this->assertEquals('array', $casts['events']);
        $this->assertEquals('array', $casts['headers']);
        $this->assertEquals('array', $casts['retry_config']);
        $this->assertEquals('boolean', $casts['is_active']);
    }
}
