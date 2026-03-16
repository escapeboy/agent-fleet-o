<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClearCueWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $team = Team::create([
            'name' => 'Default Team',
            'slug' => 'default',
            'owner_id' => $owner->id,
            'settings' => [],
        ]);
        $this->app->instance('current_team', $team);
    }

    private function makeClearCuePayload(array $overrides = []): array
    {
        return array_merge([
            'person' => [
                'id' => 'person-abc123',
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'position' => 'VP of Sales',
                'seniority' => 'VP',
                'company' => 'Acme Corp',
                'linkedin_url' => 'https://linkedin.com/in/janedoe',
                'about_me' => 'Building sales teams at B2B SaaS companies.',
            ],
            'company' => [
                'id' => 'company-xyz789',
                'company_name' => 'Acme Corp',
                'industry' => 'Software / SaaS',
                'company_size' => '150-500',
                'website' => 'https://acme.com',
                'linkedin_url' => 'https://linkedin.com/company/acme',
            ],
            'signal_context' => [
                'signal_type' => 'competitor_engagement',
                'signal_category' => 'evaluation',
                'signal_frequency' => 3,
                'competitor_mentioned' => 'CompetitorX',
                'engagement_context' => 'Liked post about switching CRM tools',
            ],
            'list_id' => 'list-001',
            'detected_at' => now()->toIso8601String(),
        ], $overrides);
    }

    /** @test */
    public function it_accepts_a_valid_clearcue_webhook_without_signature_check(): void
    {
        // No secret configured → signature validation skipped
        config(['services.clearcue.webhook_secret' => null]);

        $payload = $this->makeClearCuePayload();

        $response = $this->postJson('/api/signals/clearcue', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('ingested', 1);

        $this->assertDatabaseHas('signals', [
            'source_type' => 'clearcue',
            'source_identifier' => 'https://linkedin.com/in/janedoe',
            'source_native_id' => 'clearcue:person-abc123',
        ]);
    }

    /** @test */
    public function it_validates_hmac_signature_when_secret_is_configured(): void
    {
        $secret = 'test-webhook-secret';
        config(['services.clearcue.webhook_secret' => $secret]);

        $payload = json_encode($this->makeClearCuePayload());
        $validSignature = hash_hmac('sha256', $payload, $secret);

        $response = $this->call(
            'POST',
            '/api/signals/clearcue',
            [],
            [],
            [],
            ['HTTP_X-ClearCue-Signature' => $validSignature, 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $response->assertStatus(201);
        $this->assertEquals(1, Signal::where('source_type', 'clearcue')->count());
    }

    /** @test */
    public function it_rejects_invalid_signature_with_401(): void
    {
        config(['services.clearcue.webhook_secret' => 'real-secret']);

        $response = $this->call(
            'POST',
            '/api/signals/clearcue',
            [],
            [],
            [],
            ['HTTP_X-ClearCue-Signature' => 'bad-signature', 'CONTENT_TYPE' => 'application/json'],
            json_encode($this->makeClearCuePayload()),
        );

        $response->assertStatus(401);
        $this->assertEquals(0, Signal::where('source_type', 'clearcue')->count());
    }

    /** @test */
    public function it_deduplicates_repeated_deliveries_of_same_signal(): void
    {
        config(['services.clearcue.webhook_secret' => null]);

        $payload = $this->makeClearCuePayload();

        // First delivery
        $this->postJson('/api/signals/clearcue', $payload)->assertStatus(201);

        // Same payload again (same person.id → same source_native_id)
        // IngestSignalAction merges duplicates and returns the existing signal, so status is still 201
        $this->postJson('/api/signals/clearcue', $payload)->assertStatus(201);

        // Only one signal record should exist
        $this->assertEquals(1, Signal::where('source_type', 'clearcue')->count());

        // duplicate_count should be incremented
        $signal = Signal::where('source_type', 'clearcue')->first();
        $this->assertEquals(1, $signal->duplicate_count);
    }

    /** @test */
    public function it_includes_intent_and_category_tags(): void
    {
        config(['services.clearcue.webhook_secret' => null]);

        $this->postJson('/api/signals/clearcue', $this->makeClearCuePayload());

        $signal = Signal::where('source_type', 'clearcue')->first();

        $this->assertContains('intent', $signal->tags);
        $this->assertContains('clearcue', $signal->tags);
        $this->assertContains('evaluation', $signal->tags);
        $this->assertContains('competitor_engagement', $signal->tags);
    }

    /** @test */
    public function it_handles_batch_array_payload(): void
    {
        config(['services.clearcue.webhook_secret' => null]);

        $payload = [
            $this->makeClearCuePayload(['person' => array_merge($this->makeClearCuePayload()['person'], ['id' => 'p1', 'linkedin_url' => 'https://linkedin.com/in/person1'])]),
            $this->makeClearCuePayload(['person' => array_merge($this->makeClearCuePayload()['person'], ['id' => 'p2', 'linkedin_url' => 'https://linkedin.com/in/person2'])]),
        ];

        $response = $this->postJson('/api/signals/clearcue', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('ingested', 2);
        $this->assertEquals(2, Signal::where('source_type', 'clearcue')->count());
    }

    /** @test */
    public function it_returns_200_with_no_signals_when_payload_is_empty(): void
    {
        config(['services.clearcue.webhook_secret' => null]);

        $response = $this->postJson('/api/signals/clearcue', []);

        $response->assertStatus(422);
    }
}
