<?php

namespace Tests\Feature\Livewire\Admin;

use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use App\Domain\Signal\Jobs\RunSentryWatchdogJob;
use App\Domain\Signal\Models\SentryWatchdogRun;
use App\Livewire\Admin\SentryWatchdogPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class SentryWatchdogPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_render_the_page(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($superAdmin);

        Livewire::test(SentryWatchdogPage::class)
            ->assertOk()
            ->assertSee('Operating mode')
            ->assertSee('Recent runs');
    }

    public function test_non_super_admin_gets_forbidden(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($user);

        Livewire::test(SentryWatchdogPage::class)->assertForbidden();
    }

    public function test_selecting_a_run_loads_its_drilldown(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $integration = Integration::factory()->create([
            'driver' => 'sentry',
            'name' => 'My Sentry Project',
        ]);

        $run = SentryWatchdogRun::create([
            'integration_id' => $integration->id,
            'team_id' => $integration->team_id,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(5),
            'signals_triaged' => 7,
            'prs_opened' => 2,
            'investigate_only' => 4,
            'critical_count' => 1,
            'digest_summary' => 'Test digest contents — 7 issues processed.',
        ]);

        $this->actingAs($superAdmin);

        Livewire::test(SentryWatchdogPage::class)
            ->call('selectRun', $run->id)
            ->assertSet('selectedRunId', $run->id)
            ->assertSee('Test digest contents — 7 issues processed.')
            ->assertSee('My Sentry Project');
    }

    public function test_run_now_dispatches_job_for_enabled_sentry_integrations(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);

        Integration::factory()->create([
            'driver' => 'sentry',
            'status' => IntegrationStatus::Active,
            'config' => ['watchdog_enabled' => true],
        ]);

        // Disabled — should NOT dispatch.
        Integration::factory()->create([
            'driver' => 'sentry',
            'status' => IntegrationStatus::Active,
            'config' => ['watchdog_enabled' => false],
        ]);

        Bus::fake();

        $this->actingAs($superAdmin);

        Livewire::test(SentryWatchdogPage::class)->call('runNow');

        Bus::assertDispatchedTimes(RunSentryWatchdogJob::class, 1);
    }
}
