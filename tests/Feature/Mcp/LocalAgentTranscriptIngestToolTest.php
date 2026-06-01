<?php

namespace Tests\Feature\Mcp;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Jobs\ExportToPhoenixJob;
use App\Infrastructure\AI\Services\ClaudeCodeTranscriptParser;
use App\Infrastructure\AI\Services\PhoenixProjectResolver;
use App\Infrastructure\AI\Services\TranscriptIngestor;
use App\Mcp\Tools\Phoenix\LocalAgentTranscriptIngestTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Mcp\Request;
use Tests\TestCase;

class LocalAgentTranscriptIngestToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Transcript Team',
            'slug' => 'transcript-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($user);
        app()->instance('mcp.team_id', $this->team->id);
    }

    private function tool(): LocalAgentTranscriptIngestTool
    {
        return new LocalAgentTranscriptIngestTool(new TranscriptIngestor(new ClaudeCodeTranscriptParser, app(PhoenixProjectResolver::class)));
    }

    private function transcript(): string
    {
        return '{"type":"assistant","sessionId":"s1","timestamp":"2026-05-27T10:00:00.000Z","message":'
            .'{"role":"assistant","model":"claude-opus-4-7","content":[{"type":"text","text":"hi"}],'
            .'"usage":{"input_tokens":10,"output_tokens":5}}}';
    }

    public function test_returns_noop_when_disabled(): void
    {
        config(['llmops.transcript_ingest.enabled' => false, 'llmops.phoenix.enabled' => true]);
        Bus::fake();

        $response = $this->tool()->handle(new Request(['transcript' => $this->transcript()]));
        $payload = json_decode((string) $response->content(), true);

        $this->assertFalse($response->isError());
        $this->assertFalse($payload['ingested']);
        Bus::assertNotDispatched(ExportToPhoenixJob::class);
    }

    public function test_ingests_when_enabled(): void
    {
        config([
            'llmops.transcript_ingest.enabled' => true,
            'llmops.phoenix.enabled' => true,
            'llmops.phoenix.endpoint' => 'http://phoenix:6006',
        ]);
        Bus::fake();

        $response = $this->tool()->handle(new Request([
            'transcript' => $this->transcript(),
            'source' => 'claude-code-vps',
        ]));
        $payload = json_decode((string) $response->content(), true);

        $this->assertFalse($response->isError());
        $this->assertTrue($payload['ingested']);
        $this->assertSame('s1', $payload['session_id']);
        // 1 root + 1 assistant turn span.
        $this->assertSame(2, $payload['spans_emitted']);
        Bus::assertDispatched(ExportToPhoenixJob::class, 2);
    }

    public function test_rejects_agent_id_from_another_team(): void
    {
        config(['llmops.transcript_ingest.enabled' => true, 'llmops.phoenix.enabled' => true]);
        Bus::fake();

        $otherOwner = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team',
            'owner_id' => $otherOwner->id,
            'settings' => [],
        ]);
        $foreignAgent = Agent::factory()->for($otherTeam)->create();

        $response = $this->tool()->handle(new Request([
            'transcript' => $this->transcript(),
            'agent_id' => $foreignAgent->id,
        ]));

        $this->assertTrue($response->isError());
        Bus::assertNotDispatched(ExportToPhoenixJob::class);
    }

    public function test_accepts_agent_id_owned_by_team(): void
    {
        config([
            'llmops.transcript_ingest.enabled' => true,
            'llmops.phoenix.enabled' => true,
            'llmops.phoenix.endpoint' => 'http://phoenix:6006',
        ]);
        Bus::fake();

        $ownAgent = Agent::factory()->for($this->team)->create();

        $response = $this->tool()->handle(new Request([
            'transcript' => $this->transcript(),
            'agent_id' => $ownAgent->id,
        ]));
        $payload = json_decode((string) $response->content(), true);

        $this->assertFalse($response->isError());
        $this->assertTrue($payload['ingested']);
        Bus::assertDispatched(ExportToPhoenixJob::class, 2);
    }
}
