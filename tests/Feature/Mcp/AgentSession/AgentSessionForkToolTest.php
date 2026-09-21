<?php

namespace Tests\Feature\Mcp\AgentSession;

use App\Domain\AgentSession\Actions\AppendSessionEventAction;
use App\Domain\AgentSession\Actions\CreateAgentSessionAction;
use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\AgentSession\AgentSessionForkTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class AgentSessionForkToolTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
        app()->instance('mcp.team_id', $this->team->id);
    }

    private function sessionWithEvents(int $count): AgentSession
    {
        $session = app(CreateAgentSessionAction::class)->execute(teamId: $this->team->id);
        $session->update(['status' => AgentSessionStatus::Active]);
        $session->refresh();

        $append = app(AppendSessionEventAction::class);
        for ($i = 1; $i <= $count; $i++) {
            $append->execute($session, AgentSessionEventKind::Note, ['step' => $i]);
        }

        return $session->refresh();
    }

    public function test_forks_at_the_given_seq(): void
    {
        $source = $this->sessionWithEvents(5);

        $response = app(AgentSessionForkTool::class)
            ->handle(new Request(['session_id' => $source->id, 'at_seq' => 3]));

        $payload = json_decode($this->responseText($response), true);

        $this->assertSame($source->id, $payload['source']['id']);
        $this->assertSame(3, $payload['forked_at_seq']);
        $this->assertSame(3, $payload['copied_events']);
        $this->assertSame($source->id, $payload['child']['parent_session_id']);
        $this->assertSame('pending', $payload['child']['status']);
        // Source untouched.
        $this->assertSame('active', $payload['source']['status']);
    }

    public function test_defaults_to_the_last_seq(): void
    {
        $source = $this->sessionWithEvents(4);

        $response = app(AgentSessionForkTool::class)
            ->handle(new Request(['session_id' => $source->id]));

        $payload = json_decode($this->responseText($response), true);
        $this->assertSame(4, $payload['forked_at_seq']);
    }

    public function test_unknown_session_is_not_found(): void
    {
        $response = app(AgentSessionForkTool::class)
            ->handle(new Request(['session_id' => (string) Str::uuid()]));

        $payload = json_decode($this->responseText($response), true);
        $this->assertSame('NOT_FOUND', $payload['error']['code']);
    }

    public function test_cross_team_session_is_not_found(): void
    {
        $other = Team::create([
            'name' => 'Other',
            'slug' => 'other-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        // No events needed: the tool must refuse on team scope before it ever
        // reaches the event log.
        $foreign = app(CreateAgentSessionAction::class)->execute(teamId: $other->id);

        $response = app(AgentSessionForkTool::class)
            ->handle(new Request(['session_id' => $foreign->id]));

        $payload = json_decode($this->responseText($response), true);
        $this->assertSame('NOT_FOUND', $payload['error']['code']);
        $this->assertSame(0, AgentSession::withoutGlobalScopes()
            ->where('parent_session_id', $foreign->id)->count());
    }

    public function test_out_of_range_seq_returns_a_structured_error(): void
    {
        $source = $this->sessionWithEvents(2);

        $response = app(AgentSessionForkTool::class)
            ->handle(new Request(['session_id' => $source->id, 'at_seq' => 99]));

        $payload = json_decode($this->responseText($response), true);
        $this->assertArrayHasKey('error', $payload);
        $this->assertStringContainsString('beyond', $payload['error']['message']);
    }

    public function test_session_without_events_returns_a_failed_precondition(): void
    {
        $empty = app(CreateAgentSessionAction::class)->execute(teamId: $this->team->id);

        $response = app(AgentSessionForkTool::class)
            ->handle(new Request(['session_id' => $empty->id]));

        $payload = json_decode($this->responseText($response), true);
        $this->assertArrayHasKey('error', $payload);
        $this->assertStringContainsString('no events', $payload['error']['message']);
    }

    private function responseText(Response $response): string
    {
        return (string) $response->content();
    }
}
