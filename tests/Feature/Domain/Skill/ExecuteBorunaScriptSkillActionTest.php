<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Actions\ExecuteBorunaScriptSkillAction;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpStdioClient;
use App\Models\User;
use Illuminate\Database\PostgresConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ExecuteBorunaScriptSkillActionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    /** @var MockInterface&McpStdioClient */
    private $mcpClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-'.uniqid(),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        $this->mcpClient = Mockery::mock(McpStdioClient::class);
        $this->app->instance(McpStdioClient::class, $this->mcpClient);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeBorunaTool(?string $teamId = null, string $command = 'boruna-mcp'): Tool
    {
        return Tool::create([
            'team_id' => $teamId ?? $this->team->id,
            'name' => 'Boruna Runtime',
            'slug' => 'boruna-'.uniqid(),
            'type' => ToolType::McpStdio,
            'status' => ToolStatus::Active,
            'transport_config' => ['command' => $command, 'args' => []],
            'tool_definitions' => [],
            'settings' => [],
        ]);
    }

    private function makeSkill(array $config = []): Skill
    {
        return Skill::create([
            'team_id' => $this->team->id,
            'slug' => 'boruna-skill-'.uniqid(),
            'name' => 'Boruna Skill',
            'type' => SkillType::BorunaScript->value,
            'status' => 'active',
            'configuration' => array_merge([
                'script' => 'fn run(input) { return { result: input }; }',
            ], $config),
        ]);
    }

    public function test_executes_boruna_script_successfully_with_explicit_tool_id(): void
    {
        $tool = $this->makeBorunaTool();

        $this->mcpClient->shouldReceive('callTool')
            ->once()
            ->with(
                Mockery::on(fn (Tool $t): bool => $t->id === $tool->id),
                'boruna_run',
                Mockery::on(function (array $args) {
                    return $args['policy'] === 'deny-all'
                        && str_contains($args['script'], 'fn run')
                        && ! array_key_exists('input', $args); // empty input is filtered out
                }),
            )
            ->andReturn(json_encode(['ok' => true, 'value' => 42]));

        $skill = $this->makeSkill(['boruna_tool_id' => $tool->id]);

        $action = app(ExecuteBorunaScriptSkillAction::class);

        $result = $action->execute(
            skill: $skill,
            input: [],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals(['ok' => true, 'value' => 42], $result['output']);
        $this->assertEquals('completed', $result['execution']->status);
        $this->assertEquals(0, $result['execution']->cost_credits);
        $this->assertEquals($this->team->id, $result['execution']->team_id);
        $this->assertEquals($skill->id, $result['execution']->skill_id);
    }

    public function test_passes_input_as_json_string_when_provided(): void
    {
        $tool = $this->makeBorunaTool();

        $this->mcpClient->shouldReceive('callTool')
            ->once()
            ->withArgs(function ($_t, string $name, array $args): bool {
                return $name === 'boruna_run'
                    && $args['input'] === json_encode(['k' => 'v']);
            })
            ->andReturn('{"output": "done"}');

        $skill = $this->makeSkill(['boruna_tool_id' => $tool->id]);

        $result = app(ExecuteBorunaScriptSkillAction::class)->execute(
            skill: $skill,
            input: ['k' => 'v'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals('completed', $result['execution']->status);
    }

    public function test_falls_back_to_raw_string_output_when_response_is_not_json(): void
    {
        $tool = $this->makeBorunaTool();

        $this->mcpClient->shouldReceive('callTool')
            ->once()
            ->andReturn('not-json output');

        $skill = $this->makeSkill(['boruna_tool_id' => $tool->id]);

        $result = app(ExecuteBorunaScriptSkillAction::class)->execute(
            skill: $skill,
            input: [],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals(['output' => 'not-json output'], $result['output']);
        $this->assertEquals('completed', $result['execution']->status);
    }

    public function test_invalid_policy_is_normalised_to_deny_all(): void
    {
        $tool = $this->makeBorunaTool();

        $this->mcpClient->shouldReceive('callTool')
            ->once()
            ->withArgs(function ($_t, $_n, array $args): bool {
                return $args['policy'] === 'deny-all';
            })
            ->andReturn('{}');

        $skill = $this->makeSkill([
            'boruna_tool_id' => $tool->id,
            'policy' => 'something-malicious',
        ]);

        $result = app(ExecuteBorunaScriptSkillAction::class)->execute(
            skill: $skill,
            input: [],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals('completed', $result['execution']->status);
    }

    public function test_passes_allow_all_policy_when_explicitly_configured(): void
    {
        $tool = $this->makeBorunaTool();

        $this->mcpClient->shouldReceive('callTool')
            ->once()
            ->withArgs(function ($_t, $_n, array $args): bool {
                return $args['policy'] === 'allow-all';
            })
            ->andReturn('{}');

        $skill = $this->makeSkill([
            'boruna_tool_id' => $tool->id,
            'policy' => 'allow-all',
        ]);

        $result = app(ExecuteBorunaScriptSkillAction::class)->execute(
            skill: $skill,
            input: [],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals('completed', $result['execution']->status);
    }

    public function test_fails_when_script_config_is_missing(): void
    {
        $tool = $this->makeBorunaTool();

        $this->mcpClient->shouldNotReceive('callTool');

        $skill = Skill::create([
            'team_id' => $this->team->id,
            'slug' => 'boruna-no-script-'.uniqid(),
            'name' => 'Boruna No Script',
            'type' => SkillType::BorunaScript->value,
            'status' => 'active',
            'configuration' => ['boruna_tool_id' => $tool->id], // no script
        ]);

        $result = app(ExecuteBorunaScriptSkillAction::class)->execute(
            skill: $skill,
            input: [],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);
        $this->assertStringContainsString('script', $result['execution']->error_message);
    }

    public function test_fails_when_explicit_boruna_tool_id_does_not_resolve(): void
    {
        // Pass an explicit-but-nonexistent UUID. This forces the explicit-resolve branch
        // (which uses portable equality) instead of the auto-detect branch
        // (which uses PostgreSQL JSONB ->> operator and is exercised in a separate test).
        $this->mcpClient->shouldNotReceive('callTool');

        $skill = $this->makeSkill([
            'boruna_tool_id' => '00000000-0000-7000-8000-000000000000',
        ]);

        $result = app(ExecuteBorunaScriptSkillAction::class)->execute(
            skill: $skill,
            input: [],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);
        $this->assertStringContainsString('Boruna tool', $result['execution']->error_message);
    }

    public function test_records_failure_when_mcp_client_throws(): void
    {
        $tool = $this->makeBorunaTool();

        $this->mcpClient->shouldReceive('callTool')
            ->once()
            ->andThrow(new \RuntimeException('Boruna binary segfaulted'));

        $skill = $this->makeSkill(['boruna_tool_id' => $tool->id]);

        $result = app(ExecuteBorunaScriptSkillAction::class)->execute(
            skill: $skill,
            input: [],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);
        $this->assertStringContainsString('segfaulted', $result['execution']->error_message);
        // duration_ms must still be recorded even on failure
        $this->assertGreaterThanOrEqual(0, $result['execution']->duration_ms);
    }

    public function test_does_not_resolve_a_boruna_tool_from_another_team(): void
    {
        // Create a Boruna tool owned by a different team.
        $otherTeamUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team-'.uniqid(),
            'owner_id' => $otherTeamUser->id,
            'settings' => [],
        ]);
        $otherTeamUser->update(['current_team_id' => $otherTeam->id]);
        $otherTeam->users()->attach($otherTeamUser, ['role' => 'owner']);

        $foreignTool = $this->makeBorunaTool($otherTeam->id);

        $this->mcpClient->shouldNotReceive('callTool');

        // Pass the foreign tool ID — the executor MUST refuse to resolve it.
        $skill = $this->makeSkill(['boruna_tool_id' => $foreignTool->id]);

        $result = app(ExecuteBorunaScriptSkillAction::class)->execute(
            skill: $skill,
            input: [],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);
        $this->assertStringContainsString('Boruna tool', $result['execution']->error_message);
    }

    public function test_auto_detects_boruna_tool_by_command_substring(): void
    {
        if (! DB::connection() instanceof PostgresConnection) {
            $this->markTestSkipped('Auto-detect uses PostgreSQL JSONB ->> operator; SQLite test DB cannot exercise this path.');
        }

        $this->makeBorunaTool($this->team->id, 'boruna-mcp');

        $this->mcpClient->shouldReceive('callTool')
            ->once()
            ->andReturn('{}');

        $skill = $this->makeSkill(); // no explicit boruna_tool_id — auto-detect required

        $result = app(ExecuteBorunaScriptSkillAction::class)->execute(
            skill: $skill,
            input: [],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals('completed', $result['execution']->status);
    }
}
