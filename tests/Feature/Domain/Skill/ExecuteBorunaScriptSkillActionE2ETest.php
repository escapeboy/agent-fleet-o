<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Actions\ExecuteBorunaScriptSkillAction;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Tool\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * End-to-end: real boruna-mcp binary, real McpStdioClient, no mocks.
 *
 * Skipped automatically when /usr/local/bin/boruna-mcp is missing, so
 * developers without the rebuilt Docker image still get green tests.
 *
 * To run this locally / in CI, ensure the base image was rebuilt with
 * BORUNA_VERSION=0.2.0 (default). On a host machine, install the binary
 * separately and adjust BORUNA_BINARY env var.
 */
class ExecuteBorunaScriptSkillActionE2ETest extends TestCase
{
    use RefreshDatabase;

    private const DEFAULT_BINARY_PATH = '/usr/local/bin/boruna-mcp';

    private string $binaryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->binaryPath = (string) (getenv('BORUNA_BINARY') ?: self::DEFAULT_BINARY_PATH);

        if (! is_executable($this->binaryPath)) {
            $this->markTestSkipped(
                "boruna-mcp binary not present at {$this->binaryPath} — rebuild the Docker image with BORUNA_VERSION=0.2.0, or set BORUNA_BINARY env var.",
            );
        }

        // Allow the bundled binary through McpStdioClient's fail-close
        // allowlist for the duration of this test only — production users
        // configure this via MCP_STDIO_BINARY_ALLOWLIST in .env.
        config(['agent.mcp_stdio_binary_allowlist' => [$this->binaryPath]]);

        Cache::flush();
    }

    public function test_register_tool_command_seeds_a_boruna_tool(): void
    {
        [$user, $team] = $this->makeTeam();

        $exitCode = Artisan::call('boruna:register-tool', [
            '--team' => $team->id,
            '--binary' => $this->binaryPath,
        ]);

        $this->assertEquals(0, $exitCode, 'boruna:register-tool must exit 0.');

        $tool = Tool::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('subkind', 'boruna')
            ->first();

        $this->assertNotNull($tool, 'Tool must be persisted for the team.');
        $this->assertEquals('mcp_stdio', $tool->type->value);
        $this->assertEquals('active', $tool->status->value);
        $this->assertEquals($this->binaryPath, $tool->transport_config['command']);
    }

    public function test_register_tool_command_is_idempotent(): void
    {
        [$user, $team] = $this->makeTeam();

        Artisan::call('boruna:register-tool', ['--team' => $team->id, '--binary' => $this->binaryPath]);
        $firstId = Tool::withoutGlobalScopes()->where('team_id', $team->id)->where('subkind', 'boruna')->value('id');

        // Second invocation must NOT create a duplicate.
        Artisan::call('boruna:register-tool', ['--team' => $team->id, '--binary' => $this->binaryPath]);
        $count = Tool::withoutGlobalScopes()->where('team_id', $team->id)->where('subkind', 'boruna')->count();

        $this->assertEquals(1, $count);
        $this->assertEquals($firstId, Tool::withoutGlobalScopes()->where('team_id', $team->id)->where('subkind', 'boruna')->value('id'));
    }

    public function test_executes_real_ax_script_against_bundled_binary(): void
    {
        [$user, $team] = $this->makeTeam();

        // Register the Tool through the production-path command (so we
        // exercise that contract too).
        Artisan::call('boruna:register-tool', ['--team' => $team->id, '--binary' => $this->binaryPath]);

        $skill = Skill::create([
            'team_id' => $team->id,
            'slug' => 'boruna-e2e-'.uniqid(),
            'name' => 'Boruna E2E Smoke',
            'type' => SkillType::BorunaScript->value,
            'status' => 'active',
            'configuration' => [
                // Minimum-valid .ax program per docs/reference/ax-language.md:
                // every standalone .ax file must define `fn main() -> Int`.
                'script' => "fn main() -> Int {\n    42\n}\n",
                'policy' => 'deny-all',
            ],
        ]);

        $result = app(ExecuteBorunaScriptSkillAction::class)->execute(
            skill: $skill,
            input: [],
            teamId: $team->id,
            userId: $user->id,
        );

        // We do not assert exact output bytes — Boruna's response framing
        // may evolve. We assert only the integration contract: the executor
        // round-tripped through the real binary and produced a recorded
        // success without raising.
        $this->assertEquals(
            'completed',
            $result['execution']->status,
            "Real binary must execute the trivial .ax program. error_message={$result['execution']->error_message} output=".json_encode($result['output']),
        );
        $this->assertNotNull($result['output']);
        $this->assertEquals(0, $result['execution']->cost_credits);
        $this->assertGreaterThan(0, $result['execution']->duration_ms);
    }

    /** @return array{0: User, 1: Team} */
    private function makeTeam(): array
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'E2E Team',
            'slug' => 'e2e-team-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => 'owner']);

        return [$user, $team];
    }
}
