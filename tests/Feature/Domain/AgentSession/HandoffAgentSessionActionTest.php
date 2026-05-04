<?php

namespace Tests\Feature\Domain\AgentSession;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\AgentSession\Actions\CreateAgentSessionAction;
use App\Domain\AgentSession\Actions\HandoffAgentSessionAction;
use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\AgentSession\Models\AgentSessionEvent;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandoffAgentSessionActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_handoff_creates_target_session_and_emits_paired_events(): void
    {
        $team = Team::factory()->create();
        $sourceAgent = Agent::factory()->for($team)->create();
        $targetAgent = Agent::factory()->for($team)->create();

        $source = app(CreateAgentSessionAction::class)->execute(
            teamId: $team->id,
            agentId: $sourceAgent->id,
        );
        $source->update([
            'status' => AgentSessionStatus::Active,
            'workspace_contract_snapshot' => ['agents_md' => '# Agents'],
        ]);

        $result = app(HandoffAgentSessionAction::class)->execute(
            $source->refresh(),
            $targetAgent->id,
            'Pass to QA bot',
        );

        $this->assertFalse($result['reused']);
        $this->assertSame(AgentSessionStatus::Sleeping, $result['source']->status);
        $this->assertSame(AgentSessionStatus::Pending, $result['target']->status);
        $this->assertSame($targetAgent->id, $result['target']->agent_id);
        $this->assertSame(['agents_md' => '# Agents'], $result['target']->workspace_contract_snapshot);

        $this->assertSame(1, AgentSessionEvent::where('session_id', $source->id)
            ->where('kind', AgentSessionEventKind::HandoffOut->value)->count());
        $this->assertSame(1, AgentSessionEvent::where('session_id', $result['target']->id)
            ->where('kind', AgentSessionEventKind::HandoffIn->value)->count());
    }

    public function test_handoff_is_idempotent_within_window(): void
    {
        $team = Team::factory()->create();
        $sourceAgent = Agent::factory()->for($team)->create();
        $targetAgent = Agent::factory()->for($team)->create();
        $source = app(CreateAgentSessionAction::class)->execute(
            teamId: $team->id,
            agentId: $sourceAgent->id,
        );
        $source->update(['status' => AgentSessionStatus::Active]);
        $source->refresh();

        $first = app(HandoffAgentSessionAction::class)->execute($source, $targetAgent->id);
        $second = app(HandoffAgentSessionAction::class)->execute($source->refresh(), $targetAgent->id);

        $this->assertFalse($first['reused']);
        $this->assertTrue($second['reused']);
        $this->assertSame($first['target']->id, $second['target']->id);
        $this->assertSame(2, AgentSession::count());
    }

    public function test_cross_team_target_is_rejected(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $sourceAgent = Agent::factory()->for($teamA)->create();
        $foreignAgent = Agent::factory()->for($teamB)->create();

        $source = app(CreateAgentSessionAction::class)->execute(
            teamId: $teamA->id,
            agentId: $sourceAgent->id,
        );
        $source->update(['status' => AgentSessionStatus::Active]);
        $source->refresh();

        $this->expectException(\InvalidArgumentException::class);
        app(HandoffAgentSessionAction::class)->execute($source, $foreignAgent->id);
    }

    public function test_terminal_source_is_rejected(): void
    {
        $team = Team::factory()->create();
        $sourceAgent = Agent::factory()->for($team)->create();
        $targetAgent = Agent::factory()->for($team)->create();
        $source = app(CreateAgentSessionAction::class)->execute(
            teamId: $team->id,
            agentId: $sourceAgent->id,
        );
        $source->update(['status' => AgentSessionStatus::Cancelled, 'ended_at' => now()]);

        $this->expectException(\RuntimeException::class);
        app(HandoffAgentSessionAction::class)->execute($source->refresh(), $targetAgent->id);
    }

    public function test_disabled_target_is_rejected(): void
    {
        $team = Team::factory()->create();
        $sourceAgent = Agent::factory()->for($team)->create();
        $targetAgent = Agent::factory()->for($team)->create(['status' => AgentStatus::Disabled]);
        $source = app(CreateAgentSessionAction::class)->execute(
            teamId: $team->id,
            agentId: $sourceAgent->id,
        );
        $source->update(['status' => AgentSessionStatus::Active]);

        $this->expectException(\InvalidArgumentException::class);
        app(HandoffAgentSessionAction::class)->execute($source->refresh(), $targetAgent->id);
    }

    public function test_same_agent_target_is_rejected(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create();
        $source = app(CreateAgentSessionAction::class)->execute(
            teamId: $team->id,
            agentId: $agent->id,
        );
        $source->update(['status' => AgentSessionStatus::Active]);

        $this->expectException(\InvalidArgumentException::class);
        app(HandoffAgentSessionAction::class)->execute($source->refresh(), $agent->id);
    }
}
