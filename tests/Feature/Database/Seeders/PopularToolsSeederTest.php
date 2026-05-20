<?php

namespace Tests\Feature\Database\Seeders;

use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Enums\ToolRiskLevel;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use Database\Seeders\PopularToolsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PopularToolsSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seeder needs at least one team in community mode (it picks
        // Team::first() when CloudServiceProvider isn't loaded).
        Team::factory()->create();
    }

    /**
     * Pin the Harbormaster popular-tool entry. PRs that touch
     * PopularToolsSeeder should keep this row's shape stable — it's
     * the public surface FleetQ users see in the /tools UI.
     */
    public function test_seeds_harbormaster_with_expected_shape(): void
    {
        $this->seed(PopularToolsSeeder::class);

        $tool = Tool::withoutGlobalScopes()->where('slug', 'harbormaster')->first();
        $this->assertNotNull($tool, 'PopularToolsSeeder did not produce a Harbormaster row');

        $this->assertSame('Harbormaster', $tool->name);
        $this->assertSame(ToolType::McpStdio, $tool->type);
        $this->assertSame(ToolStatus::Disabled, $tool->status);
        $this->assertSame(ToolRiskLevel::Read, $tool->risk_level);

        // Transport: uvx --prerelease=allow harbormaster-mcp
        $this->assertSame('uvx', $tool->transport_config['command']);
        $this->assertSame(
            ['--prerelease=allow', 'harbormaster-mcp'],
            $tool->transport_config['args'],
        );
    }

    /**
     * The 6 v1 tools must all be advertised — adding/removing tools is
     * a breaking contract change for FleetQ users who built workflows
     * against them.
     */
    public function test_harbormaster_advertises_all_six_v1_tools(): void
    {
        $this->seed(PopularToolsSeeder::class);

        $tool = Tool::withoutGlobalScopes()->where('slug', 'harbormaster')->firstOrFail();
        $names = collect($tool->tool_definitions)->pluck('name')->all();

        $this->assertEqualsCanonicalizing(
            [
                'list_projects',
                'list_hosts',
                'project_status',
                'ask_project',
                'delegate_task',
                'fan_out_ask',
            ],
            $names,
        );
    }

    /**
     * ask_project's input_schema is the most-touched and breakage here
     * (e.g. accidentally dropping `host`) silently turns off SSH from
     * the FleetQ UI.
     */
    public function test_ask_project_schema_includes_required_and_optional_fields(): void
    {
        $this->seed(PopularToolsSeeder::class);

        $tool = Tool::withoutGlobalScopes()->where('slug', 'harbormaster')->firstOrFail();
        $askProject = collect($tool->tool_definitions)
            ->firstWhere('name', 'ask_project');

        $this->assertNotNull($askProject);
        $schema = $askProject['input_schema'];

        $this->assertEqualsCanonicalizing(
            ['name', 'question'],
            $schema['required'],
        );
        $this->assertArrayHasKey('host', $schema['properties']);
        $this->assertArrayHasKey('max_turns', $schema['properties']);
    }

    /**
     * Re-running the seeder must be idempotent — `updateOrCreate` on
     * `slug` means a second run should not duplicate or change the row.
     */
    public function test_re_running_seeder_is_idempotent(): void
    {
        $this->seed(PopularToolsSeeder::class);
        $first = Tool::withoutGlobalScopes()->where('slug', 'harbormaster')->firstOrFail();

        $this->seed(PopularToolsSeeder::class);

        $count = Tool::withoutGlobalScopes()->where('slug', 'harbormaster')->count();
        $this->assertSame(1, $count, 'Re-seeding produced a duplicate row');

        $second = Tool::withoutGlobalScopes()->where('slug', 'harbormaster')->firstOrFail();
        $this->assertSame($first->id, $second->id, 'Re-seeding replaced the existing row');
    }
}
