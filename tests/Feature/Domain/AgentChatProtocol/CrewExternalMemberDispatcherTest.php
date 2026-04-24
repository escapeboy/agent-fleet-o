<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\AgentChatProtocol;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Enums\ExternalAgentStatus;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewStatus;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Crew\Services\CrewExternalMemberDispatcher;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrewExternalMemberDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private ExternalAgent $external;

    private CrewExecution $execution;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Crew Test',
            'slug' => 'crew-external-'.Str::random(4),
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $this->external = ExternalAgent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $this->team->id,
            'name' => 'Remote Assistant',
            'slug' => 'remote-'.Str::random(4),
            'endpoint_url' => 'https://example.com/api/v1/agents/remote',
            'status' => ExternalAgentStatus::Active,
        ]);

        $coordinator = Agent::factory()->for($this->team)->create(['name' => 'Coord']);
        $qa = Agent::factory()->for($this->team)->create(['name' => 'QA']);

        $crew = Crew::factory()->for($this->team)->create([
            'user_id' => $user->id,
            'coordinator_agent_id' => $coordinator->id,
            'qa_agent_id' => $qa->id,
            'process_type' => CrewProcessType::Fanout,
            'status' => CrewStatus::Active,
        ]);

        $this->execution = CrewExecution::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $this->team->id,
            'crew_id' => $crew->id,
            'user_id' => $user->id,
            'goal' => 'solve this problem',
            'process_type' => CrewProcessType::Fanout,
            'status' => 'executing',
            'coordinator_snapshot' => [],
        ]);
    }

    public function test_external_member_dispatch_succeeds(): void
    {
        Http::fake([
            'example.com/api/v1/agents/remote/chat' => Http::response([
                'msg_id' => (string) Str::uuid7(),
                'content' => 'remote reply',
                'from' => 'remote-assistant',
            ], 200),
        ]);

        $task = CrewTaskExecution::create([
            'id' => (string) Str::uuid7(),
            'crew_execution_id' => $this->execution->id,
            'agent_id' => null,
            'external_agent_id' => $this->external->id,
            'title' => 'External task',
            'description' => 'do something remote',
            'status' => CrewTaskStatus::Pending,
            'input_context' => ['original_goal' => 'do something remote'],
            'depends_on' => [],
            'attempt_number' => 1,
            'max_attempts' => 3,
            'sort_order' => 0,
        ]);

        app(CrewExternalMemberDispatcher::class)->dispatch($task, $this->execution);

        $task->refresh();
        $this->assertSame(CrewTaskStatus::Completed->value, $task->status->value);
        $this->assertNotNull($task->completed_at);
        $this->assertStringContainsString('remote reply', json_encode($task->output));
    }

    public function test_external_member_dispatch_marks_task_failed_on_remote_error(): void
    {
        Http::fake([
            'example.com/api/v1/agents/remote/chat' => Http::response(['error' => 'server down'], 500),
        ]);

        $task = CrewTaskExecution::create([
            'id' => (string) Str::uuid7(),
            'crew_execution_id' => $this->execution->id,
            'agent_id' => null,
            'external_agent_id' => $this->external->id,
            'title' => 'External task',
            'description' => 'remote work',
            'status' => CrewTaskStatus::Pending,
            'input_context' => [],
            'depends_on' => [],
            'attempt_number' => 1,
            'max_attempts' => 3,
            'sort_order' => 0,
        ]);

        app(CrewExternalMemberDispatcher::class)->dispatch($task, $this->execution);

        $task->refresh();
        $this->assertSame(CrewTaskStatus::Failed->value, $task->status->value);
        $this->assertNotNull($task->error_message);
    }

    public function test_external_member_with_missing_external_agent_marks_failed(): void
    {
        $ephemeral = ExternalAgent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $this->team->id,
            'name' => 'Soon Gone',
            'slug' => 'soon-gone-'.Str::random(4),
            'endpoint_url' => 'https://example.com/gone',
            'status' => ExternalAgentStatus::Active,
        ]);
        $ephemeralId = $ephemeral->id;

        $task = CrewTaskExecution::create([
            'id' => (string) Str::uuid7(),
            'crew_execution_id' => $this->execution->id,
            'agent_id' => null,
            'external_agent_id' => $ephemeralId,
            'title' => 'Orphan task',
            'description' => 'no external agent',
            'status' => CrewTaskStatus::Pending,
            'input_context' => [],
            'depends_on' => [],
            'attempt_number' => 1,
            'max_attempts' => 3,
            'sort_order' => 0,
        ]);

        // Hard delete the external agent to simulate the orphan case.
        $ephemeral->forceDelete();

        // Task.external_agent_id was NOT cleared (set null on delete is async / deferred in SQLite).
        // Dispatcher should handle the missing external agent gracefully.
        $task->external_agent_id = $ephemeralId;
        $task->saveQuietly();

        app(CrewExternalMemberDispatcher::class)->dispatch($task, $this->execution);

        $task->refresh();
        $this->assertSame(CrewTaskStatus::Failed->value, $task->status->value);
        $this->assertStringContainsString('not found', (string) $task->error_message);
    }
}
