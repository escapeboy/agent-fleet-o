<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Actions\ExecuteBorunaScriptSkillAction;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpStdioClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

        // Default handler for capability_set_hash lookups (v1.0+).
        // Tests that constrain to 'boruna_run' will fall through to this for cap_list calls.
        $this->mcpClient->shouldReceive('callTool')
            ->with(Mockery::any(), 'boruna_capability_list', Mockery::any())
            ->andReturn(json_encode(['capability_set_hash' => 'test-cap-hash']));

        // Each test starts with an empty result cache.
        Cache::flush();
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
                'script' => "fn main() -> Int { 42 }",
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
                        && str_contains($args['source'], 'fn main') // Boruna v0.2.0 expects `source`, not `script`
                        && ! array_key_exists('input', $args); // boruna_run does not accept an input param
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

    public function test_input_is_recorded_but_not_forwarded_to_boruna(): void
    {
        $tool = $this->makeBorunaTool();

        // Boruna v0.2.0 boruna_run does NOT accept an `input` parameter. Our
        // contract is to record the caller's input on SkillExecution for
        // audit purposes but not forward it. Authors who need runtime input
        // are expected to interpolate literals into the .ax source.
        $this->mcpClient->shouldReceive('callTool')
            ->once()
            ->withArgs(function ($_t, string $name, array $args): bool {
                return $name === 'boruna_run'
                    && ! array_key_exists('input', $args)
                    && array_key_exists('source', $args);
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
        $this->assertEquals(['k' => 'v'], $result['execution']->input);
    }

    public function test_falls_back_to_raw_string_output_when_response_is_not_json(): void
    {
        $tool = $this->makeBorunaTool();

        $this->mcpClient->shouldReceive('callTool')
            ->once()
            ->with(Mockery::any(), 'boruna_run', Mockery::any())
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
            ->withArgs(function ($_t, string $name, array $args): bool {
                return $name === 'boruna_run' && $args['policy'] === 'deny-all';
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
            ->withArgs(function ($_t, string $name, array $args): bool {
                return $name === 'boruna_run' && $args['policy'] === 'allow-all';
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
            ->with(Mockery::any(), 'boruna_run', Mockery::any())
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

    public function test_auto_detects_boruna_tool_via_subkind(): void
    {
        $this->makeBorunaTool($this->team->id, 'boruna-mcp');

        $this->mcpClient->shouldReceive('callTool')
            ->once()
            ->with(Mockery::any(), 'boruna_run', Mockery::any())
            ->andReturn('{}');

        $skill = $this->makeSkill(); // no explicit boruna_tool_id — subkind='boruna' resolution required

        $result = app(ExecuteBorunaScriptSkillAction::class)->execute(
            skill: $skill,
            input: [],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals('completed', $result['execution']->status);
    }

    public function test_saving_hook_auto_tags_subkind_for_boruna_command(): void
    {
        $tool = Tool::create([
            'team_id' => $this->team->id,
            'name' => 'Boruna Auto-Tag',
            'slug' => 'boruna-auto-'.uniqid(),
            'type' => ToolType::McpStdio,
            'status' => ToolStatus::Active,
            'transport_config' => ['command' => '/usr/local/bin/boruna-mcp'],
            'tool_definitions' => [],
            'settings' => [],
        ]);

        $this->assertEquals('boruna', $tool->subkind);
    }

    public function test_saving_hook_does_not_tag_unrelated_mcp_stdio_tool(): void
    {
        $tool = Tool::create([
            'team_id' => $this->team->id,
            'name' => 'Unrelated Tool',
            'slug' => 'unrelated-'.uniqid(),
            'type' => ToolType::McpStdio,
            'status' => ToolStatus::Active,
            'transport_config' => ['command' => '/usr/local/bin/some-other-tool'],
            'tool_definitions' => [],
            'settings' => [],
        ]);

        $this->assertNull($tool->subkind);
    }

    public function test_cache_hit_skips_mcp_call_and_returns_stored_output(): void
    {
        $tool = $this->makeBorunaTool();

        // First call hits the MCP server once and primes the cache.
        $this->mcpClient->shouldReceive('callTool')
            ->once()
            ->with(Mockery::any(), 'boruna_run', Mockery::any())
            ->andReturn(json_encode(['ok' => true, 'value' => 7]));

        $skill = $this->makeSkill(['boruna_tool_id' => $tool->id]);

        $action = app(ExecuteBorunaScriptSkillAction::class);

        $first = $action->execute(
            skill: $skill,
            input: ['k' => 'v'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );
        $this->assertEquals(['ok' => true, 'value' => 7], $first['output']);

        // Second call with identical inputs MUST not invoke the MCP client.
        // The Mockery `once()` expectation above already enforces this — a
        // second invocation would fail the test.
        $second = $action->execute(
            skill: $skill,
            input: ['k' => 'v'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );
        $this->assertEquals(['ok' => true, 'value' => 7], $second['output']);
        $this->assertEquals('completed', $second['execution']->status);
        $this->assertEquals(0, $second['execution']->cost_credits);

        // Two execution records exist — one fresh, one from cache.
        $this->assertEquals(
            2,
            SkillExecution::where('skill_id', $skill->id)->count(),
        );
    }

    public function test_cache_miss_for_different_input(): void
    {
        $tool = $this->makeBorunaTool();

        // Two different inputs MUST result in two MCP calls.
        $this->mcpClient->shouldReceive('callTool')
            ->twice()
            ->with(Mockery::any(), 'boruna_run', Mockery::any())
            ->andReturn(json_encode(['n' => 1]), json_encode(['n' => 2]));

        $skill = $this->makeSkill(['boruna_tool_id' => $tool->id]);
        $action = app(ExecuteBorunaScriptSkillAction::class);

        $r1 = $action->execute(
            skill: $skill,
            input: ['k' => 'a'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );
        $r2 = $action->execute(
            skill: $skill,
            input: ['k' => 'b'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals(['n' => 1], $r1['output']);
        $this->assertEquals(['n' => 2], $r2['output']);
    }

    public function test_failures_are_not_cached(): void
    {
        $tool = $this->makeBorunaTool();

        // First call throws; second call must hit the MCP client again
        // (i.e. failures are not memoised) and succeeds.
        $this->mcpClient->shouldReceive('callTool')
            ->twice()
            ->with(Mockery::any(), 'boruna_run', Mockery::any())
            ->andReturnUsing(
                function () { throw new \RuntimeException('binary crashed'); },
                fn () => json_encode(['ok' => true]),
            );

        $skill = $this->makeSkill(['boruna_tool_id' => $tool->id]);
        $action = app(ExecuteBorunaScriptSkillAction::class);

        $first = $action->execute(
            skill: $skill,
            input: [],
            teamId: $this->team->id,
            userId: $this->user->id,
        );
        $this->assertEquals('failed', $first['execution']->status);

        $second = $action->execute(
            skill: $skill,
            input: [],
            teamId: $this->team->id,
            userId: $this->user->id,
        );
        $this->assertEquals('completed', $second['execution']->status);
        $this->assertEquals(['ok' => true], $second['output']);
    }

    public function test_cache_key_is_isolated_per_team(): void
    {
        // Same script + same input + same policy in two teams MUST hit the
        // MCP server twice (no cross-tenant leak through the cache layer).
        $toolA = $this->makeBorunaTool($this->team->id);

        $userB = User::factory()->create();
        $teamB = Team::create([
            'name' => 'Team B',
            'slug' => 'team-b-'.uniqid(),
            'owner_id' => $userB->id,
            'settings' => [],
        ]);
        $userB->update(['current_team_id' => $teamB->id]);
        $teamB->users()->attach($userB, ['role' => 'owner']);
        $toolB = $this->makeBorunaTool($teamB->id);

        $this->mcpClient->shouldReceive('callTool')
            ->twice()
            ->with(Mockery::any(), 'boruna_run', Mockery::any())
            ->andReturn(json_encode(['team' => 'A']), json_encode(['team' => 'B']));

        $skillA = $this->makeSkill(['boruna_tool_id' => $toolA->id]);

        $skillB = Skill::create([
            'team_id' => $teamB->id,
            'slug' => 'boruna-skill-b-'.uniqid(),
            'name' => 'Boruna Skill B',
            'type' => SkillType::BorunaScript->value,
            'status' => 'active',
            'configuration' => [
                'script' => "fn main() -> Int { 42 }", // identical to skillA
                'boruna_tool_id' => $toolB->id,
            ],
        ]);

        $action = app(ExecuteBorunaScriptSkillAction::class);

        $rA = $action->execute(skill: $skillA, input: [], teamId: $this->team->id, userId: $this->user->id);
        $rB = $action->execute(skill: $skillB, input: [], teamId: $teamB->id, userId: $userB->id);

        $this->assertEquals(['team' => 'A'], $rA['output']);
        $this->assertEquals(['team' => 'B'], $rB['output']);
    }

    public function test_passes_structured_policy_through_unchanged(): void
    {
        $tool = $this->makeBorunaTool();

        $structuredPolicy = [
            'schema_version' => 1,
            'default_allow' => false,
            'rules' => [
                'net.fetch' => ['allow' => true, 'budget' => 0],
                'fs.write' => ['allow' => false, 'budget' => 0],
            ],
            'net_policy' => [
                'allowed_domains' => ['api.openai.com'],
            ],
        ];

        $this->mcpClient->shouldReceive('callTool')
            ->once()
            ->withArgs(function ($_t, string $name, array $args) use ($structuredPolicy): bool {
                // The structured policy must reach the Boruna MCP server verbatim
                // — no string coercion, no key reordering through array_filter etc.
                return $name === 'boruna_run'
                    && $args['policy'] === $structuredPolicy;
            })
            ->andReturn(json_encode(['ok' => true]));

        $skill = $this->makeSkill([
            'boruna_tool_id' => $tool->id,
            'policy' => $structuredPolicy,
        ]);

        $result = app(ExecuteBorunaScriptSkillAction::class)->execute(
            skill: $skill,
            input: [],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals('completed', $result['execution']->status);
    }

    public function test_invalid_structured_policy_falls_back_to_deny_all(): void
    {
        $tool = $this->makeBorunaTool();

        // Missing the required `default_allow` key — must be rejected and
        // collapsed to the safe shorthand 'deny-all'.
        $brokenPolicy = ['rules' => ['net.fetch' => ['allow' => true, 'budget' => 0]]];

        $this->mcpClient->shouldReceive('callTool')
            ->once()
            ->withArgs(function ($_t, string $name, array $args): bool {
                return $name === 'boruna_run' && $args['policy'] === 'deny-all';
            })
            ->andReturn('{}');

        $skill = $this->makeSkill([
            'boruna_tool_id' => $tool->id,
            'policy' => $brokenPolicy,
        ]);

        $result = app(ExecuteBorunaScriptSkillAction::class)->execute(
            skill: $skill,
            input: [],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals('completed', $result['execution']->status);
    }

    public function test_cache_key_distinguishes_structured_from_legacy_policy(): void
    {
        $tool = $this->makeBorunaTool();

        // Two different policy shapes (legacy 'deny-all' vs. structured Policy
        // with default_allow=false) MUST produce two cache misses, even though
        // they're semantically similar — different shapes are different keys.
        $this->mcpClient->shouldReceive('callTool')
            ->twice()
            ->with(Mockery::any(), 'boruna_run', Mockery::any())
            ->andReturn(json_encode(['legacy' => true]), json_encode(['structured' => true]));

        $action = app(ExecuteBorunaScriptSkillAction::class);

        $legacy = $this->makeSkill([
            'boruna_tool_id' => $tool->id,
            'policy' => 'deny-all',
        ]);
        $structured = Skill::create([
            'team_id' => $this->team->id,
            'slug' => 'boruna-structured-'.uniqid(),
            'name' => 'Structured policy',
            'type' => SkillType::BorunaScript->value,
            'status' => 'active',
            'configuration' => [
                'script' => "fn main() -> Int { 42 }",
                'boruna_tool_id' => $tool->id,
                'policy' => ['default_allow' => false],
            ],
        ]);

        $r1 = $action->execute(skill: $legacy, input: [], teamId: $this->team->id, userId: $this->user->id);
        $r2 = $action->execute(skill: $structured, input: [], teamId: $this->team->id, userId: $this->user->id);

        $this->assertEquals(['legacy' => true], $r1['output']);
        $this->assertEquals(['structured' => true], $r2['output']);
    }

    public function test_saving_hook_respects_existing_subkind(): void
    {
        $tool = Tool::create([
            'team_id' => $this->team->id,
            'name' => 'Manually Tagged',
            'slug' => 'manual-'.uniqid(),
            'type' => ToolType::McpStdio,
            'status' => ToolStatus::Active,
            'subkind' => 'custom-tag',
            'transport_config' => ['command' => '/usr/local/bin/boruna-mcp'],
            'tool_definitions' => [],
            'settings' => [],
        ]);

        // Explicit subkind is not overwritten even when the command would auto-tag.
        $this->assertEquals('custom-tag', $tool->subkind);
    }
}
