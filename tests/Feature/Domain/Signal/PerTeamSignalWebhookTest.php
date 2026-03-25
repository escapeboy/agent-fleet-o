<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\SignalConnectorSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PerTeamSignalWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private SignalConnectorSetting $setting;

    private string $secret = 'test-secret-abcdef1234567890abcdef1234567890abcdef1234567890abcdef12345678';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsWithRedis::class]);

        Queue::fake();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $this->setting = SignalConnectorSetting::create([
            'team_id' => $this->team->id,
            'driver' => 'webhook',
            'webhook_secret' => $this->secret,
            'is_active' => true,
            'metadata' => [],
        ]);
    }

    public function test_valid_hmac_ingests_signal(): void
    {
        $payload = json_encode(['title' => 'Test Event', 'body' => 'Hello']);
        $signature = hash_hmac('sha256', $payload, $this->secret);

        $response = $this->postJson(
            "/api/signals/webhook/{$this->team->id}",
            json_decode($payload, true),
            [
                'X-Webhook-Signature' => $signature,
                'Content-Type' => 'application/json',
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonFragment(['ingested' => 1]);
    }

    public function test_invalid_hmac_returns_401(): void
    {
        $payload = ['title' => 'Test Event'];

        $response = $this->postJson(
            "/api/signals/webhook/{$this->team->id}",
            $payload,
            ['X-Webhook-Signature' => 'invalid-signature'],
        );

        $response->assertStatus(401);
        $response->assertJsonFragment(['error' => 'Invalid signature']);
    }

    public function test_missing_signature_header_returns_401(): void
    {
        $response = $this->postJson(
            "/api/signals/webhook/{$this->team->id}",
            ['title' => 'Test'],
        );

        $response->assertStatus(401);
    }

    public function test_unknown_driver_returns_404(): void
    {
        $payload = ['title' => 'Test'];
        $signature = hash_hmac('sha256', json_encode($payload), $this->secret);

        $response = $this->postJson(
            "/api/signals/unknowndriver/{$this->team->id}",
            $payload,
            ['X-Webhook-Signature' => $signature],
        );

        $response->assertStatus(404);
    }

    public function test_unconfigured_setting_returns_404(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);

        $response = $this->postJson(
            "/api/signals/webhook/{$otherTeam->id}",
            ['title' => 'Test'],
            ['X-Webhook-Signature' => 'any'],
        );

        $response->assertStatus(404);
    }

    public function test_inactive_setting_returns_404(): void
    {
        $this->setting->update(['is_active' => false]);

        $payload = ['title' => 'Test'];
        $signature = hash_hmac('sha256', json_encode($payload), $this->secret);

        $response = $this->postJson(
            "/api/signals/webhook/{$this->team->id}",
            $payload,
            ['X-Webhook-Signature' => $signature],
        );

        $response->assertStatus(404);
    }

    public function test_grace_period_accepts_previous_secret(): void
    {
        $oldSecret = 'old-secret-abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234';
        $newSecret = 'new-secret-abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234';

        // Simulate a recent rotation: previous_webhook_secret still within 1-hour grace
        $this->setting->update([
            'webhook_secret' => $newSecret,
            'previous_webhook_secret' => $oldSecret,
            'secret_rotated_at' => now()->subMinutes(30),
        ]);

        $payload = json_encode(['title' => 'Grace Period Test']);
        $oldSignature = hash_hmac('sha256', $payload, $oldSecret);

        $response = $this->postJson(
            "/api/signals/webhook/{$this->team->id}",
            json_decode($payload, true),
            [
                'X-Webhook-Signature' => $oldSignature,
                'Content-Type' => 'application/json',
            ],
        );

        $response->assertStatus(201);
    }

    public function test_grace_period_expired_rejects_old_secret(): void
    {
        $oldSecret = 'old-secret-abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234';
        $newSecret = 'new-secret-abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234';

        // Rotation was more than 1 hour ago — grace period expired
        $this->setting->update([
            'webhook_secret' => $newSecret,
            'previous_webhook_secret' => $oldSecret,
            'secret_rotated_at' => now()->subHours(2),
        ]);

        $payload = json_encode(['title' => 'Expired Grace Test']);
        $oldSignature = hash_hmac('sha256', $payload, $oldSecret);

        $response = $this->postJson(
            "/api/signals/webhook/{$this->team->id}",
            json_decode($payload, true),
            [
                'X-Webhook-Signature' => $oldSignature,
                'Content-Type' => 'application/json',
            ],
        );

        $response->assertStatus(401);
    }

    public function test_signals_are_scoped_to_correct_team(): void
    {
        // Create a second team with the same driver
        $user2 = User::factory()->create();
        $team2 = Team::create([
            'name' => 'Team Two',
            'slug' => 'team-two',
            'owner_id' => $user2->id,
            'settings' => [],
        ]);
        $secret2 = 'team2-secret-abcdef1234567890abcdef1234567890abcdef1234567890abcdef12';
        SignalConnectorSetting::create([
            'team_id' => $team2->id,
            'driver' => 'webhook',
            'webhook_secret' => $secret2,
            'is_active' => true,
            'metadata' => [],
        ]);

        // Send to team 1 URL with team 1 secret
        $payload = json_encode(['title' => 'Team 1 Signal']);
        $sig1 = hash_hmac('sha256', $payload, $this->secret);

        $this->postJson(
            "/api/signals/webhook/{$this->team->id}",
            json_decode($payload, true),
            ['X-Webhook-Signature' => $sig1, 'Content-Type' => 'application/json'],
        )->assertStatus(201);

        // Using team 2 secret on team 1 URL must fail
        $sig2 = hash_hmac('sha256', $payload, $secret2);

        $this->postJson(
            "/api/signals/webhook/{$this->team->id}",
            json_decode($payload, true),
            ['X-Webhook-Signature' => $sig2, 'Content-Type' => 'application/json'],
        )->assertStatus(401);
    }

    public function test_empty_payload_returns_422(): void
    {
        // Empty object — no fields
        $payload = json_encode([]);
        $signature = hash_hmac('sha256', $payload, $this->secret);

        $response = $this->call(
            'POST',
            "/api/signals/webhook/{$this->team->id}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-Webhook-Signature' => $signature,
            ],
            $payload,
        );

        $response->assertStatus(422);
    }
}
