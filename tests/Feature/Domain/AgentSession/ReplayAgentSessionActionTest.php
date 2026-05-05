<?php

namespace Tests\Feature\Domain\AgentSession;

use App\Domain\AgentSession\Actions\AppendSessionEventAction;
use App\Domain\AgentSession\Actions\CreateAgentSessionAction;
use App\Domain\AgentSession\Actions\ReplayAgentSessionAction;
use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplayAgentSessionActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_session_returns_zero_totals(): void
    {
        $team = Team::factory()->create();
        $session = app(CreateAgentSessionAction::class)->execute(teamId: $team->id);

        $summary = app(ReplayAgentSessionAction::class)->execute($session);

        $this->assertSame(0, $summary->totalEvents);
        $this->assertSame([], $summary->eventsByKind);
        $this->assertSame(0, $summary->llmTotalTokens);
        $this->assertSame(0.0, $summary->llmTotalCostUsd);
        $this->assertSame(0, $summary->toolCallCount);
        $this->assertSame(0, $summary->handoffCount);
        $this->assertSame([], $summary->events);
        $this->assertNull($summary->nextSinceSeq);
    }

    public function test_summary_aggregates_llm_cost_and_tool_failures(): void
    {
        $team = Team::factory()->create();
        $session = app(CreateAgentSessionAction::class)->execute(teamId: $team->id);
        $append = app(AppendSessionEventAction::class);

        $append->execute($session, AgentSessionEventKind::LlmCall, ['tokens_total' => 100, 'cost_usd' => 0.001]);
        $append->execute($session, AgentSessionEventKind::LlmCall, ['tokens_total' => 250, 'cost_usd' => 0.0025]);
        $append->execute($session, AgentSessionEventKind::ToolCall, ['tool' => 'bash']);
        $append->execute($session, AgentSessionEventKind::ToolResult, ['error' => 'timeout']);
        $append->execute($session, AgentSessionEventKind::ToolResult, ['ok' => true]);

        $summary = app(ReplayAgentSessionAction::class)->execute($session);

        $this->assertSame(5, $summary->totalEvents);
        $this->assertSame(350, $summary->llmTotalTokens);
        $this->assertEqualsWithDelta(0.0035, $summary->llmTotalCostUsd, 0.00001);
        $this->assertSame(1, $summary->toolCallCount);
        $this->assertSame(1, $summary->toolFailureCount);
        $this->assertSame(2, $summary->eventsByKind[AgentSessionEventKind::LlmCall->value]);
    }

    public function test_since_seq_pagination(): void
    {
        $team = Team::factory()->create();
        $session = app(CreateAgentSessionAction::class)->execute(teamId: $team->id);
        $append = app(AppendSessionEventAction::class);
        for ($i = 0; $i < 10; $i++) {
            $append->execute($session, AgentSessionEventKind::Note, ['n' => $i]);
        }

        $page1 = app(ReplayAgentSessionAction::class)->execute($session, sinceSeq: 0, limit: 4);
        $this->assertCount(4, $page1->events);
        $this->assertSame(4, $page1->nextSinceSeq);
        $this->assertSame(10, $page1->totalEvents);

        $page2 = app(ReplayAgentSessionAction::class)->execute($session, sinceSeq: $page1->nextSinceSeq, limit: 4);
        $this->assertCount(4, $page2->events);
        $this->assertSame(8, $page2->nextSinceSeq);

        $page3 = app(ReplayAgentSessionAction::class)->execute($session, sinceSeq: $page2->nextSinceSeq, limit: 4);
        $this->assertCount(2, $page3->events);
        $this->assertNull($page3->nextSinceSeq);
    }

    public function test_kinds_filter_returns_only_matching_events(): void
    {
        $team = Team::factory()->create();
        $session = app(CreateAgentSessionAction::class)->execute(teamId: $team->id);
        $append = app(AppendSessionEventAction::class);
        $append->execute($session, AgentSessionEventKind::Note, ['n' => 1]);
        $append->execute($session, AgentSessionEventKind::ToolCall, ['t' => 'bash']);
        $append->execute($session, AgentSessionEventKind::Note, ['n' => 2]);

        $summary = app(ReplayAgentSessionAction::class)->execute(
            $session,
            kinds: [AgentSessionEventKind::ToolCall],
        );

        $this->assertCount(1, $summary->events);
        $this->assertSame('tool_call', $summary->events[0]['kind']);
        // Stats still cover ALL events
        $this->assertSame(3, $summary->totalEvents);
    }

    public function test_duration_seconds_uses_started_and_ended_timestamps(): void
    {
        $team = Team::factory()->create();
        $session = app(CreateAgentSessionAction::class)->execute(teamId: $team->id);
        $session->update([
            'started_at' => now()->subMinutes(5),
            'ended_at' => now(),
            'status' => AgentSessionStatus::Completed,
        ]);

        $summary = app(ReplayAgentSessionAction::class)->execute($session->refresh());

        $this->assertNotNull($summary->durationSeconds);
        $this->assertGreaterThanOrEqual(290, $summary->durationSeconds);
        $this->assertLessThanOrEqual(310, $summary->durationSeconds);
    }
}
