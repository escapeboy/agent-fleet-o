<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Signal\Models\Signal;

class SignalControllerTest extends ApiTestCase
{
    private function createSignal(array $overrides = []): Signal
    {
        return Signal::create(array_merge([
            'team_id' => $this->team->id,
            'source_type' => 'rss',
            'source_identifier' => 'https://example.com/feed',
            'payload' => ['title' => 'Test Signal', 'url' => 'https://example.com/1'],
            'content_hash' => hash('sha256', json_encode(['title' => 'Test Signal', 'url' => 'https://example.com/1'])),
            'tags' => ['tech', 'ai'],
            'received_at' => now(),
        ], $overrides));
    }

    public function test_can_list_signals(): void
    {
        $this->actingAsApiUser();
        $this->createSignal(['content_hash' => hash('sha256', 'one')]);
        $this->createSignal(['content_hash' => hash('sha256', 'two')]);

        $response = $this->getJson('/api/v1/signals');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'source_type', 'source_identifier', 'payload']],
            ]);
    }

    public function test_can_filter_signals_by_source_type(): void
    {
        $this->actingAsApiUser();
        $this->createSignal(['source_type' => 'rss', 'content_hash' => hash('sha256', 'rss1')]);
        $this->createSignal(['source_type' => 'webhook', 'content_hash' => hash('sha256', 'wh1')]);

        $response = $this->getJson('/api/v1/signals?filter[source_type]=rss');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source_type', 'rss');
    }

    public function test_can_show_signal(): void
    {
        $this->actingAsApiUser();
        $signal = $this->createSignal();

        $response = $this->getJson("/api/v1/signals/{$signal->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $signal->id)
            ->assertJsonPath('data.source_type', 'rss');
    }

    public function test_can_create_signal(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/signals', [
            'source_type' => 'manual',
            'source_identifier' => 'user-input',
            'payload' => ['content' => 'Test input signal'],
            'tags' => ['manual'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.source_type', 'manual');

        $this->assertDatabaseHas('signals', ['source_type' => 'manual']);
    }

    public function test_create_signal_validates_required_fields(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/signals', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['source_type', 'source_identifier', 'payload']);
    }

    public function test_unauthenticated_cannot_list_signals(): void
    {
        $response = $this->getJson('/api/v1/signals');

        $response->assertStatus(401);
    }
}
