<?php

namespace Tests\Feature\Domain\Memory;

use App\Domain\Memory\Jobs\DistillTeamEventsJob;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DistillEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeam(): Team
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);

        return $team;
    }

    public function test_dispatches_one_job_per_team(): void
    {
        Queue::fake();
        $this->makeTeam();
        $this->makeTeam();

        $this->artisan('memory:distill-events')->assertSuccessful();

        Queue::assertPushed(DistillTeamEventsJob::class, Team::count());
    }

    public function test_team_option_scopes_to_a_single_team(): void
    {
        Queue::fake();
        $target = $this->makeTeam();
        $this->makeTeam();

        $this->artisan('memory:distill-events', ['--team' => $target->id])->assertSuccessful();

        Queue::assertPushed(DistillTeamEventsJob::class, 1);
        Queue::assertPushed(
            DistillTeamEventsJob::class,
            fn (DistillTeamEventsJob $job) => $job->teamId === $target->id,
        );
    }

    public function test_dry_run_does_not_dispatch_jobs(): void
    {
        Queue::fake();
        $this->makeTeam();

        $this->artisan('memory:distill-events', ['--dry-run' => true])->assertSuccessful();

        Queue::assertNotPushed(DistillTeamEventsJob::class);
    }

    public function test_disabled_config_skips_processing(): void
    {
        Queue::fake();
        $this->makeTeam();
        config(['memory.distillation.enabled' => false]);

        $this->artisan('memory:distill-events')->assertSuccessful();

        Queue::assertNotPushed(DistillTeamEventsJob::class);
    }
}
