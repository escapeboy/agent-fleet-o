<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Actions\RegisterBorunaToolAction;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterBorunaToolActionTest extends TestCase
{
    use RefreshDatabase;

    private string $fakeBinary;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        // Use the test runner's PHP interpreter as a stand-in executable —
        // the action only checks `is_executable()`, not what the binary does.
        $this->fakeBinary = (string) (PHP_BINARY ?: '/usr/bin/env');
        if (! is_executable($this->fakeBinary)) {
            $this->markTestSkipped('Could not find an executable to use as fake binary.');
        }

        $owner = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Action Team',
            'slug' => 'action-team-'.uniqid(),
            'owner_id' => $owner->id,
            'settings' => [],
        ]);
        $owner->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($owner, ['role' => 'owner']);
    }

    public function test_creates_a_new_tool_when_none_exists(): void
    {
        $action = app(RegisterBorunaToolAction::class);

        $result = $action->execute(teamId: $this->team->id, binary: $this->fakeBinary);

        $this->assertTrue($result['created']);
        $this->assertEquals(ToolType::McpStdio, $result['tool']->type);
        $this->assertEquals(ToolStatus::Active, $result['tool']->status);
        $this->assertEquals('boruna', $result['tool']->subkind);
        $this->assertEquals($this->fakeBinary, $result['tool']->transport_config['command']);
        $this->assertEquals($this->team->id, $result['tool']->team_id);
    }

    public function test_is_idempotent_no_force(): void
    {
        $action = app(RegisterBorunaToolAction::class);

        $first = $action->execute(teamId: $this->team->id, binary: $this->fakeBinary);
        $second = $action->execute(teamId: $this->team->id, binary: $this->fakeBinary);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertEquals($first['tool']->id, $second['tool']->id);
        $this->assertEquals(
            1,
            Tool::withoutGlobalScopes()->where('team_id', $this->team->id)->where('subkind', 'boruna')->count(),
        );
    }

    public function test_force_repoints_at_a_new_binary(): void
    {
        $action = app(RegisterBorunaToolAction::class);

        $first = $action->execute(teamId: $this->team->id, binary: $this->fakeBinary);

        // Re-point at /usr/bin/env (or whichever is our second executable).
        $alt = '/usr/bin/env';
        if (! is_executable($alt) || $alt === $this->fakeBinary) {
            $this->markTestSkipped('Need a second executable for repoint test.');
        }

        $second = $action->execute(teamId: $this->team->id, binary: $alt, force: true);

        $this->assertEquals($first['tool']->id, $second['tool']->id, 'Same row should be reused.');
        $this->assertEquals($alt, $second['tool']->fresh()->transport_config['command']);
    }

    public function test_throws_when_binary_not_executable(): void
    {
        $action = app(RegisterBorunaToolAction::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found or not executable/');

        $action->execute(teamId: $this->team->id, binary: '/no/such/binary/exists');
    }

    public function test_two_teams_get_independent_tools(): void
    {
        $action = app(RegisterBorunaToolAction::class);
        $action->execute(teamId: $this->team->id, binary: $this->fakeBinary);

        $userB = User::factory()->create();
        $teamB = Team::create([
            'name' => 'Team B',
            'slug' => 'team-b-'.uniqid(),
            'owner_id' => $userB->id,
            'settings' => [],
        ]);
        $userB->update(['current_team_id' => $teamB->id]);
        $teamB->users()->attach($userB, ['role' => 'owner']);

        $action->execute(teamId: $teamB->id, binary: $this->fakeBinary);

        $aTools = Tool::withoutGlobalScopes()->where('team_id', $this->team->id)->where('subkind', 'boruna')->count();
        $bTools = Tool::withoutGlobalScopes()->where('team_id', $teamB->id)->where('subkind', 'boruna')->count();

        $this->assertEquals(1, $aTools);
        $this->assertEquals(1, $bTools);
    }
}
