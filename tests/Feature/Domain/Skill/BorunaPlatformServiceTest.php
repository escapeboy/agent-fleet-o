<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Actions\RegisterBorunaToolAction;
use App\Domain\Skill\Services\BorunaPlatformService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BorunaPlatformServiceTest extends TestCase
{
    use RefreshDatabase;

    private BorunaPlatformService $service;

    private Team $team;

    private string $fakeBinary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BorunaPlatformService::class);
        $this->fakeBinary = (string) (PHP_BINARY ?: '/usr/bin/env');

        $owner = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Platform Team',
            'slug' => 'platform-team-'.uniqid(),
            'owner_id' => $owner->id,
            'settings' => [],
        ]);
        $owner->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($owner, ['role' => 'owner']);
    }

    public function test_status_binary_missing_when_path_not_executable(): void
    {
        config(['agent.mcp_stdio_binary_allowlist' => []]);

        $status = $this->service->statusForTeam(
            teamId: $this->team->id,
            binary: '/no/such/path',
        );

        $this->assertEquals('binary_missing', $status);
    }

    public function test_status_not_allowlisted_when_present_but_excluded(): void
    {
        config(['agent.mcp_stdio_binary_allowlist' => []]);
        config(['agent.mcp_stdio_allow_any_binary' => false]);

        $status = $this->service->statusForTeam(
            teamId: $this->team->id,
            binary: $this->fakeBinary,
        );

        $this->assertEquals('not_allowlisted', $status);
    }

    public function test_status_ready_to_enable_when_allowlisted_and_no_tool_yet(): void
    {
        config(['agent.mcp_stdio_binary_allowlist' => [$this->fakeBinary]]);

        $status = $this->service->statusForTeam(
            teamId: $this->team->id,
            binary: $this->fakeBinary,
        );

        $this->assertEquals('ready_to_enable', $status);
    }

    public function test_status_enabled_after_action_creates_tool(): void
    {
        config(['agent.mcp_stdio_binary_allowlist' => [$this->fakeBinary]]);

        app(RegisterBorunaToolAction::class)->execute(
            teamId: $this->team->id,
            binary: $this->fakeBinary,
        );

        $status = $this->service->statusForTeam(
            teamId: $this->team->id,
            binary: $this->fakeBinary,
        );

        $this->assertEquals('enabled', $status);
    }

    public function test_allow_any_binary_overrides_empty_allowlist(): void
    {
        config(['agent.mcp_stdio_binary_allowlist' => []]);
        config(['agent.mcp_stdio_allow_any_binary' => true]);

        $this->assertTrue($this->service->isBinaryAllowed($this->fakeBinary));
    }
}
