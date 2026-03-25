<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\ConnectorSignalSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LinearSubscriptionWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private ConnectorSignalSubscription $subscription;

    private string $secret = 'linear-test-secret-abcdef1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsWithRedis::class]);

        Queue::fake();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Linear Test Team',
            'slug' => 'linear-test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $integration = Integration::create([
            'team_id' => $this->team->id,
            'driver' => 'linear',
            'name' => 'Linear (test)',
            'status' => 'active',
        ]);

        $this->subscription = ConnectorSignalSubscription::create([
            'team_id' => $this->team->id,
            'integration_id' => $integration->id,
            'name' => 'Engineering team',
            'driver' => 'linear',
            'filter_config' => ['resource_types' => ['Issue', 'Comment']],
            'is_active' => true,
            'webhook_secret' => $this->secret,
            'webhook_status' => 'registered',
        ]);
    }

    public function test_valid_linear_issue_webhook_ingests_signal(): void
    {
        $payload = [
            'type' => 'Issue',
            'action' => 'create',
            'data' => [
                'identifier' => 'ENG-42',
                'title' => 'Fix the bug',
                'team' => ['name' => 'Engineering'],
                'labels' => [['name' => 'bug']],
            ],
            'webhookTimestamp' => now()->timestamp * 1000,
        ];

        $rawBody = json_encode($payload);
        $signature = hash_hmac('sha256', $rawBody, $this->secret);

        $response = $this->postJson(
            "/api/signals/subscription/{$this->subscription->id}",
            $payload,
            [
                'Linear-Signature' => $signature,
                'Linear-Delivery' => 'delivery-abc-123',
                'Content-Type' => 'application/json',
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonFragment(['ingested' => 1]);

        $this->assertDatabaseHas('signals', [
            'team_id' => $this->team->id,
            'source_type' => 'linear',
            'source_identifier' => 'ENG-42',
        ]);
    }

    public function test_invalid_linear_signature_returns_403(): void
    {
        $payload = ['type' => 'Issue', 'action' => 'create', 'data' => ['identifier' => 'ENG-1']];

        $response = $this->postJson(
            "/api/signals/subscription/{$this->subscription->id}",
            $payload,
            ['Linear-Signature' => 'invalidsignature'],
        );

        $response->assertStatus(403);
        $this->assertDatabaseMissing('signals', ['source_type' => 'linear']);
    }

    public function test_non_issue_type_returns_200_with_zero_ingested(): void
    {
        $payload = [
            'type' => 'Project',
            'action' => 'create',
            'data' => ['id' => 'proj-123'],
        ];

        $rawBody = json_encode($payload);
        $signature = hash_hmac('sha256', $rawBody, $this->secret);

        $response = $this->postJson(
            "/api/signals/subscription/{$this->subscription->id}",
            $payload,
            ['Linear-Signature' => $signature],
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['ingested' => 0]);
    }

    public function test_action_filter_excludes_non_matching_actions(): void
    {
        $this->subscription->update([
            'filter_config' => ['filter_actions' => ['create']],
        ]);

        $payload = [
            'type' => 'Issue',
            'action' => 'update',
            'data' => ['identifier' => 'ENG-99', 'team' => ['name' => 'Eng'], 'labels' => []],
        ];

        $rawBody = json_encode($payload);
        $signature = hash_hmac('sha256', $rawBody, $this->secret);

        $response = $this->postJson(
            "/api/signals/subscription/{$this->subscription->id}",
            $payload,
            ['Linear-Signature' => $signature],
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['ingested' => 0]);
    }
}
