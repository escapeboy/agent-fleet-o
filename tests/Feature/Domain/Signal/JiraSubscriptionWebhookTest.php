<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\ConnectorSignalSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Jira Cloud webhook subscriptions do not use HMAC signing.
 * Security is the opaque subscription UUID in the callback URL.
 * The webhook_secret is null on the subscription record.
 */
class JiraSubscriptionWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private ConnectorSignalSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Jira Test Team',
            'slug' => 'jira-test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $integration = Integration::create([
            'team_id' => $this->team->id,
            'driver' => 'jira',
            'name' => 'Jira (test)',
            'status' => 'active',
        ]);

        $this->subscription = ConnectorSignalSubscription::create([
            'team_id' => $this->team->id,
            'integration_id' => $integration->id,
            'name' => 'Engineering project',
            'driver' => 'jira',
            'filter_config' => ['project_key' => 'ENG'],
            'is_active' => true,
            'webhook_secret' => null, // Jira does not sign webhook payloads
            'webhook_status' => 'registered',
        ]);
    }

    public function test_jira_issue_created_webhook_ingests_signal(): void
    {
        $payload = [
            'webhookEvent' => 'jira:issue_created',
            'issue' => [
                'key' => 'ENG-42',
                'fields' => [
                    'summary' => 'Fix the login bug',
                    'project' => ['key' => 'ENG', 'name' => 'Engineering'],
                    'labels' => ['bug'],
                    'status' => ['name' => 'To Do'],
                ],
            ],
        ];

        $response = $this->postJson(
            "/api/signals/subscription/{$this->subscription->id}",
            $payload,
            ['Content-Type' => 'application/json'],
        );

        $response->assertStatus(201);
        $response->assertJsonFragment(['ingested' => 1]);

        $this->assertDatabaseHas('signals', [
            'team_id' => $this->team->id,
            'source_type' => 'jira',
            'source_identifier' => 'ENG-42',
        ]);
    }

    public function test_jira_issue_updated_webhook_ingests_signal(): void
    {
        $payload = [
            'webhookEvent' => 'jira:issue_updated',
            'issue' => [
                'key' => 'ENG-10',
                'fields' => [
                    'summary' => 'Updated summary',
                    'project' => ['key' => 'ENG', 'name' => 'Engineering'],
                    'labels' => [],
                ],
            ],
        ];

        $rawBody = json_encode($payload);
        $response = $this->postJson(
            "/api/signals/subscription/{$this->subscription->id}",
            $payload,
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('signals', ['source_identifier' => 'ENG-10']);
    }

    public function test_unknown_event_returns_200_with_zero_ingested(): void
    {
        $payload = [
            'webhookEvent' => 'jira:sprint_started',
            'sprint' => ['id' => 12, 'name' => 'Sprint 1'],
        ];

        $response = $this->postJson(
            "/api/signals/subscription/{$this->subscription->id}",
            $payload,
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['ingested' => 0]);
        $this->assertDatabaseMissing('signals', ['source_type' => 'jira']);
    }

    public function test_project_key_filter_excludes_other_projects(): void
    {
        // subscription is filtered to 'ENG'; this payload is from 'OPS'
        $payload = [
            'webhookEvent' => 'jira:issue_created',
            'issue' => [
                'key' => 'OPS-5',
                'fields' => [
                    'summary' => 'Unrelated ops issue',
                    'project' => ['key' => 'OPS', 'name' => 'Operations'],
                    'labels' => [],
                ],
            ],
        ];

        $response = $this->postJson(
            "/api/signals/subscription/{$this->subscription->id}",
            $payload,
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['ingested' => 0]);
    }

    public function test_inactive_subscription_returns_404(): void
    {
        $this->subscription->update(['is_active' => false]);

        $payload = ['webhookEvent' => 'jira:issue_created', 'issue' => ['key' => 'ENG-1', 'fields' => []]];

        $response = $this->postJson(
            "/api/signals/subscription/{$this->subscription->id}",
            $payload,
        );

        $response->assertStatus(404);
    }
}
