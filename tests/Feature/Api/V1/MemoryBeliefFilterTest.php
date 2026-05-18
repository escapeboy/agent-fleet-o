<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Enums\MemoryBeliefStatus;
use App\Domain\Memory\Enums\MemoryBeliefType;
use App\Domain\Memory\Models\Memory;

class MemoryBeliefFilterTest extends ApiTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createMemory(array $overrides = []): Memory
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);

        return Memory::create(array_merge([
            'team_id' => $this->team->id,
            'agent_id' => $agent->id,
            'content' => 'Test memory content',
            'source_type' => 'test',
            'confidence' => 0.9,
            'importance' => 0.5,
        ], $overrides));
    }

    public function test_index_filters_by_belief_type(): void
    {
        $this->actingAsApiUser();
        $this->createMemory(['belief_type' => MemoryBeliefType::Decision]);
        $this->createMemory(['belief_type' => MemoryBeliefType::Preference]);

        $response = $this->getJson('/api/v1/memories?filter[belief_type]=decision');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('decision', $response->json('data.0.belief_type'));
    }

    public function test_index_filters_by_belief_status(): void
    {
        $this->actingAsApiUser();
        $this->createMemory(['belief_status' => MemoryBeliefStatus::Active]);
        $this->createMemory(['belief_status' => MemoryBeliefStatus::Superseded]);

        $response = $this->getJson('/api/v1/memories?filter[belief_status]=superseded');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('superseded', $response->json('data.0.belief_status'));
    }

    public function test_index_filters_by_domain(): void
    {
        $this->actingAsApiUser();
        $this->createMemory(['domain' => 'domain:code']);
        $this->createMemory(['domain' => 'domain:writing']);

        $response = $this->getJson('/api/v1/memories?filter[domain]=domain:code');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('domain:code', $response->json('data.0.domain'));
    }

    public function test_resource_exposes_belief_fields(): void
    {
        $this->actingAsApiUser();
        $memory = $this->createMemory([
            'belief_type' => MemoryBeliefType::Preference,
            'why_it_matters' => 'Keeps replies terse.',
            'domain' => 'user:universal',
        ]);

        $response = $this->getJson("/api/v1/memories/{$memory->id}");

        $response->assertOk()->assertJsonPath('data.belief_type', 'preference')
            ->assertJsonPath('data.why_it_matters', 'Keeps replies terse.')
            ->assertJsonPath('data.domain', 'user:universal');
    }
}
