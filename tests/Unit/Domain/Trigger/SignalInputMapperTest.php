<?php

namespace Tests\Unit\Domain\Trigger;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use App\Domain\Trigger\Services\SignalInputMapper;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignalInputMapperTest extends TestCase
{
    use RefreshDatabase;

    private SignalInputMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new SignalInputMapper;
    }

    private function makeSignal(array $payload, string $sourceType = 'webhook'): Signal
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);

        return Signal::create([
            'team_id' => $team->id,
            'source_type' => $sourceType,
            'source_identifier' => $sourceType.'-test',
            'content_hash' => md5(json_encode($payload).uniqid()),
            'payload' => $payload,
            'received_at' => now(),
        ]);
    }

    public function test_empty_mapping_returns_empty_array(): void
    {
        $signal = $this->makeSignal(['event' => 'test']);
        $result = $this->mapper->map(null, $signal);

        $this->assertEmpty($result);
    }

    public function test_maps_top_level_payload_field(): void
    {
        $signal = $this->makeSignal(['title' => 'Bug Report']);
        $result = $this->mapper->map(['ticket_title' => 'title'], $signal);

        $this->assertEquals('Bug Report', $result['ticket_title']);
    }

    public function test_maps_dot_notation_nested_path(): void
    {
        $signal = $this->makeSignal(['metadata' => ['severity' => 'high', 'priority' => 5]]);
        $result = $this->mapper->map(['severity' => 'metadata.severity', 'priority' => 'metadata.priority'], $signal);

        $this->assertEquals('high', $result['severity']);
        $this->assertEquals(5, $result['priority']);
    }

    public function test_missing_path_maps_to_null(): void
    {
        $signal = $this->makeSignal(['event' => 'order.placed']);
        $result = $this->mapper->map(['ticket_title' => 'metadata.title'], $signal);

        $this->assertNull($result['ticket_title']);
    }

    public function test_injects_signal_metadata(): void
    {
        $signal = $this->makeSignal(['event' => 'test'], 'github');
        $result = $this->mapper->map(['ev' => 'event'], $signal);

        $this->assertEquals($signal->id, $result['_signal_id']);
        $this->assertEquals('github', $result['_signal_source']);
        $this->assertNotNull($result['_signal_received_at']);
    }

    public function test_multiple_mappings(): void
    {
        $signal = $this->makeSignal([
            'title' => 'Deploy failed',
            'severity' => 'critical',
            'repo' => 'agent-fleet',
        ]);

        $result = $this->mapper->map([
            'alert_title' => 'title',
            'alert_severity' => 'severity',
            'repository' => 'repo',
        ], $signal);

        $this->assertEquals('Deploy failed', $result['alert_title']);
        $this->assertEquals('critical', $result['alert_severity']);
        $this->assertEquals('agent-fleet', $result['repository']);
    }
}
