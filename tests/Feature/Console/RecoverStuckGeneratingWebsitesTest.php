<?php

namespace Tests\Feature\Console;

use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Shared\Models\Team;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test: `websites:recover-stuck` was crashing in production with
 * `BadMethodCallException: Call to undefined method Website::crewExecution()`
 * because the Website model didn't expose the `crew_execution_id` FK as an
 * Eloquent relationship even though the column existed on the table.
 */
class RecoverStuckGeneratingWebsitesTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_runs_without_crashing_when_no_stuck_websites(): void
    {
        $this->artisan('websites:recover-stuck')->assertExitCode(0);
    }

    public function test_recovers_website_when_linked_crew_execution_failed(): void
    {
        $team = Team::factory()->create();

        // Crew + coordinator agent are NOT NULL upstream of CrewExecution.crew_id.
        // Build the dependency chain explicitly so this test exercises the
        // recover-stuck command, not factory plumbing.
        $agent = \App\Domain\Agent\Models\Agent::factory()->create(['team_id' => $team->id]);
        $crew = \App\Domain\Crew\Models\Crew::forceCreate([
            'team_id' => $team->id,
            'user_id' => $team->owner_id ?? \App\Models\User::factory()->create()->id,
            'name' => 'Test crew',
            'slug' => 'test-crew-'.bin2hex(random_bytes(3)),
            'description' => 'fixture',
            'process_type' => \App\Domain\Crew\Enums\CrewProcessType::Sequential,
            'max_task_iterations' => 3,
            'quality_threshold' => 0.7,
            'status' => \App\Domain\Crew\Enums\CrewStatus::Active,
            'settings' => [],
            'coordinator_agent_id' => $agent->id,
            'qa_agent_id' => $agent->id,
        ]);

        $execution = CrewExecution::create([
            'team_id' => $team->id,
            'crew_id' => $crew->id,
            'goal' => 'Generate website',
            'status' => CrewExecutionStatus::Failed,
            'config_snapshot' => [],
            'started_at' => now()->subMinutes(30),
        ]);

        $website = Website::create([
            'team_id' => $team->id,
            'name' => 'Generating…',
            'slug' => 'site-'.bin2hex(random_bytes(3)),
            'status' => WebsiteStatus::Generating,
            'crew_execution_id' => $execution->id,
        ]);

        $this->artisan('websites:recover-stuck')->assertExitCode(0);

        $website->refresh();
        $this->assertSame(WebsiteStatus::Draft, $website->status);
        $this->assertSame('Failed generation', $website->name);
    }

    public function test_recovers_orphaned_website_with_no_execution_after_two_hours(): void
    {
        $team = Team::factory()->create();

        $website = Website::create([
            'team_id' => $team->id,
            'name' => 'Generating…',
            'slug' => 'orphan-'.bin2hex(random_bytes(3)),
            'status' => WebsiteStatus::Generating,
            'crew_execution_id' => null,
        ]);
        // Backdate created_at past the 2-hour threshold the command checks.
        $website->forceFill(['created_at' => now()->subHours(3)])->save();

        $this->artisan('websites:recover-stuck')->assertExitCode(0);

        $website->refresh();
        $this->assertSame(WebsiteStatus::Draft, $website->status);
    }

    public function test_does_not_touch_recently_created_orphan(): void
    {
        $team = Team::factory()->create();

        $website = Website::create([
            'team_id' => $team->id,
            'name' => 'Generating…',
            'slug' => 'fresh-'.bin2hex(random_bytes(3)),
            'status' => WebsiteStatus::Generating,
            'crew_execution_id' => null,
        ]);

        $this->artisan('websites:recover-stuck')->assertExitCode(0);

        $website->refresh();
        $this->assertSame(WebsiteStatus::Generating, $website->status);
    }
}
