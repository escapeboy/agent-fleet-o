<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Tool;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Actions\CreateToolsetAction;
use App\Domain\Tool\Actions\DeleteToolsetAction;
use App\Domain\Tool\Actions\UpdateToolsetAction;
use App\Domain\Tool\Models\Toolset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ToolsetCrudTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-toolset-crud',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    public function test_create_toolset_sets_correct_team_id_and_name(): void
    {
        $action = app(CreateToolsetAction::class);

        $toolset = $action->execute(
            teamId: $this->team->id,
            name: 'My Toolset',
            description: 'A test toolset',
            toolIds: [],
        );

        $this->assertSame($this->team->id, $toolset->team_id);
        $this->assertSame('My Toolset', $toolset->name);
    }

    public function test_create_toolset_generates_slug_from_name(): void
    {
        $action = app(CreateToolsetAction::class);

        $toolset = $action->execute(
            teamId: $this->team->id,
            name: 'My Awesome Toolset',
            description: '',
            toolIds: [],
        );

        $this->assertSame('my-awesome-toolset', $toolset->slug);
    }

    public function test_duplicate_slug_in_same_team_appends_suffix(): void
    {
        $action = app(CreateToolsetAction::class);

        $first = $action->execute(
            teamId: $this->team->id,
            name: 'Duplicate Name',
            description: '',
            toolIds: [],
        );

        $second = $action->execute(
            teamId: $this->team->id,
            name: 'Duplicate Name',
            description: '',
            toolIds: [],
        );

        $this->assertSame('duplicate-name', $first->slug);
        $this->assertSame('duplicate-name-2', $second->slug);
    }

    public function test_update_toolset_updates_fields(): void
    {
        $toolset = Toolset::create([
            'team_id' => $this->team->id,
            'name' => 'Original',
            'slug' => 'original',
            'description' => 'Old description',
            'tool_ids' => [],
            'tags' => [],
        ]);

        $action = app(UpdateToolsetAction::class);
        $updated = $action->execute($toolset, [
            'name' => 'Updated Name',
            'description' => 'New description',
            'tool_ids' => ['abc-123'],
        ]);

        $this->assertSame('Updated Name', $updated->name);
        $this->assertSame('New description', $updated->description);
        $this->assertSame(['abc-123'], $updated->tool_ids);
    }

    public function test_delete_toolset_detaches_from_agents_and_deletes(): void
    {
        $toolset = Toolset::create([
            'team_id' => $this->team->id,
            'name' => 'To Delete',
            'slug' => 'to-delete',
            'description' => '',
            'tool_ids' => [],
            'tags' => [],
        ]);

        $agent = Agent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $this->team->id,
            'name' => 'Test Agent',
            'slug' => 'test-agent-delete',
            'role' => 'assistant',
            'goal' => 'help',
            'backstory' => 'test',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet',
            'status' => AgentStatus::Active,
        ]);

        $agent->toolsets()->attach($toolset->id);
        $this->assertCount(1, $agent->toolsets);

        $action = app(DeleteToolsetAction::class);
        $action->execute($toolset);

        $this->assertSoftDeleted('toolsets', ['id' => $toolset->id]);
        $this->assertDatabaseMissing('agent_toolset', ['toolset_id' => $toolset->id]);
    }

    public function test_toolset_is_team_scoped(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team-toolset',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);

        Toolset::create([
            'team_id' => $otherTeam->id,
            'name' => 'Other Team Toolset',
            'slug' => 'other-team-toolset-item',
            'description' => '',
            'tool_ids' => [],
            'tags' => [],
        ]);

        Toolset::create([
            'team_id' => $this->team->id,
            'name' => 'My Team Toolset',
            'slug' => 'my-team-toolset-item',
            'description' => '',
            'tool_ids' => [],
            'tags' => [],
        ]);

        $visible = Toolset::all();

        $this->assertCount(1, $visible);
        $this->assertSame($this->team->id, $visible->first()->team_id);
    }
}
