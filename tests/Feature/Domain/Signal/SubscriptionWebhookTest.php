<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\ConnectorSignalSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SubscriptionWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private ConnectorSignalSubscription $subscription;

    private string $secret = 'test-webhook-secret-1234567890abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-sub',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $integration = Integration::create([
            'team_id' => $this->team->id,
            'driver' => 'github',
            'name' => 'GitHub (test)',
            'status' => 'active',
        ]);

        $this->subscription = ConnectorSignalSubscription::create([
            'team_id' => $this->team->id,
            'integration_id' => $integration->id,
            'name' => 'owner/repo',
            'driver' => 'github',
            'filter_config' => ['repo' => 'owner/repo'],
            'is_active' => true,
            'webhook_secret' => $this->secret,
            'webhook_status' => 'registered',
        ]);
    }

    public function test_valid_github_issue_webhook_ingests_signal(): void
    {
        $payload = [
            'action' => 'opened',
            'issue' => [
                'number' => 42,
                'node_id' => 'issue_node_abc',
                'labels' => [],
            ],
            'repository' => [
                'full_name' => 'owner/repo',
                'node_id' => 'repo_node_xyz',
            ],
        ];

        $rawBody = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $rawBody, $this->secret);

        $response = $this->postJson(
            "/api/signals/subscription/{$this->subscription->id}",
            $payload,
            [
                'X-Hub-Signature-256' => $signature,
                'X-GitHub-Event' => 'issues',
                'Content-Type' => 'application/json',
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonFragment(['ingested' => 1]);

        $this->assertDatabaseHas('signals', [
            'team_id' => $this->team->id,
            'source_type' => 'github',
            'source_identifier' => 'owner/repo#42',
        ]);
    }

    public function test_invalid_signature_returns_403(): void
    {
        $payload = ['action' => 'opened', 'issue' => ['number' => 1], 'repository' => ['full_name' => 'owner/repo']];

        $response = $this->postJson(
            "/api/signals/subscription/{$this->subscription->id}",
            $payload,
            [
                'X-Hub-Signature-256' => 'sha256=invalidsignature',
                'X-GitHub-Event' => 'issues',
                'Content-Type' => 'application/json',
            ],
        );

        $response->assertStatus(403);
        $this->assertDatabaseMissing('signals', ['source_type' => 'github']);
    }

    public function test_inactive_subscription_returns_404(): void
    {
        $this->subscription->update(['is_active' => false]);

        $rawBody = json_encode(['action' => 'opened']);
        $signature = 'sha256='.hash_hmac('sha256', $rawBody, $this->secret);

        $response = $this->postJson(
            "/api/signals/subscription/{$this->subscription->id}",
            ['action' => 'opened'],
            ['X-Hub-Signature-256' => $signature],
        );

        $response->assertStatus(404);
    }

    public function test_filtered_event_type_returns_200_with_zero_ingested(): void
    {
        // Push event filtered to specific branch that doesn't match
        $this->subscription->update([
            'filter_config' => ['repo' => 'owner/repo', 'filter_branches' => ['main']],
        ]);

        $payload = [
            'ref' => 'refs/heads/feature-branch',
            'after' => 'abc123',
            'repository' => ['full_name' => 'owner/repo'],
        ];

        $rawBody = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $rawBody, $this->secret);

        $response = $this->postJson(
            "/api/signals/subscription/{$this->subscription->id}",
            $payload,
            [
                'X-Hub-Signature-256' => $signature,
                'X-GitHub-Event' => 'push',
            ],
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['ingested' => 0]);
        $this->assertDatabaseMissing('signals', ['source_type' => 'github']);
    }

    public function test_github_push_to_allowed_branch_ingests_signal(): void
    {
        $this->subscription->update([
            'filter_config' => ['repo' => 'owner/repo', 'filter_branches' => ['main']],
        ]);

        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'deadbeef1234',
            'repository' => ['full_name' => 'owner/repo'],
        ];

        $rawBody = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $rawBody, $this->secret);

        $response = $this->postJson(
            "/api/signals/subscription/{$this->subscription->id}",
            $payload,
            [
                'X-Hub-Signature-256' => $signature,
                'X-GitHub-Event' => 'push',
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonFragment(['ingested' => 1]);

        $this->assertDatabaseHas('signals', [
            'team_id' => $this->team->id,
            'source_type' => 'github',
            'source_identifier' => 'owner/repo:refs/heads/main',
        ]);
    }
}
