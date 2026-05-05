<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\AgentChatProtocol;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Enums\ExternalAgentStatus;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\Crew\Enums\CrewMemberRole;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewChatMessage;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewMember;
use App\Domain\Crew\Services\CrewChatRoomOrchestrator;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrewChatRoomExternalMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_member_contributes_to_chat_room_round(): void
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'CR Test',
            'slug' => 'cr-ext-'.Str::random(4),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);

        $coordinator = Agent::factory()->for($team)->create(['name' => 'Coord']);
        $qa = Agent::factory()->for($team)->create(['name' => 'QA']);

        $crew = Crew::factory()->for($team)->create([
            'user_id' => $user->id,
            'coordinator_agent_id' => $coordinator->id,
            'qa_agent_id' => $qa->id,
            'process_type' => CrewProcessType::ChatRoom,
            'status' => CrewStatus::Active,
        ]);

        $external = ExternalAgent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $team->id,
            'name' => 'Remote Contributor',
            'slug' => 'remote-chat-'.Str::random(4),
            'endpoint_url' => 'https://example.com/api/v1/agents/remote',
            'status' => ExternalAgentStatus::Active,
        ]);

        CrewMember::create([
            'id' => (string) Str::uuid7(),
            'crew_id' => $crew->id,
            'agent_id' => null,
            'external_agent_id' => $external->id,
            'member_kind' => 'external',
            'role' => CrewMemberRole::Worker,
            'sort_order' => 0,
        ]);

        $execution = CrewExecution::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $team->id,
            'crew_id' => $crew->id,
            'user_id' => $user->id,
            'goal' => 'discuss the topic',
            'process_type' => CrewProcessType::ChatRoom,
            'status' => 'executing',
            'config_snapshot' => ['process_type' => 'chat_room', 'max_chat_rounds' => 1],
            'coordinator_snapshot' => [],
        ]);

        Http::fake([
            'example.com/api/v1/agents/remote/chat' => Http::response([
                'msg_id' => (string) Str::uuid7(),
                'content' => 'I think we should explore option A further.',
                'from' => 'remote-contributor',
            ], 200),
        ]);

        // Directly trigger a single round execution via reflection on the private method.
        $orchestrator = app(CrewChatRoomOrchestrator::class);
        $method = new \ReflectionMethod($orchestrator, 'executeRound');
        $method->setAccessible(true);
        $members = $crew->fresh()->workerMembers()->with(['agent', 'externalAgent'])->get();
        $method->invoke($orchestrator, $execution, $members, 1);

        $messages = CrewChatMessage::where('crew_execution_id', $execution->id)->get();
        $this->assertCount(1, $messages);
        $this->assertSame('Remote Contributor', $messages->first()->agent_name);
        $this->assertStringContainsString('option A', $messages->first()->content);
        $this->assertSame($external->id, $messages->first()->metadata['external_agent_id'] ?? null);
    }
}
