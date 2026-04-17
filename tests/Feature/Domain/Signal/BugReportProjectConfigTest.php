<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\BugReportProjectConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\V1\ApiTestCase;

class BugReportProjectConfigTest extends ApiTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_upsert_creates_config(): void
    {
        $this->actingAs($this->user);

        $response = $this->putJson('/api/v1/bug-report-configs/myapp', [
            'config' => [
                'test_command' => 'php artisan test',
                'lint_command' => 'vendor/bin/pint --test',
                'framework' => 'laravel',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('project', 'myapp');

        $this->assertDatabaseHas('bug_report_project_configs', [
            'team_id' => $this->team->id,
            'project' => 'myapp',
        ]);
    }

    public function test_upsert_updates_existing_config(): void
    {
        $this->actingAs($this->user);

        BugReportProjectConfig::create([
            'team_id' => $this->team->id,
            'project' => 'myapp',
            'config' => ['test_command' => 'old command'],
        ]);

        $this->putJson('/api/v1/bug-report-configs/myapp', [
            'config' => ['test_command' => 'new command'],
        ])->assertStatus(200);

        $this->assertDatabaseCount('bug_report_project_configs', 1);

        $config = BugReportProjectConfig::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('project', 'myapp')
            ->first();

        $this->assertEquals('new command', $config->config['test_command']);
    }

    public function test_show_returns_config(): void
    {
        $this->actingAs($this->user);

        BugReportProjectConfig::create([
            'team_id' => $this->team->id,
            'project' => 'myapp',
            'config' => ['framework' => 'laravel', 'test_command' => 'php artisan test'],
        ]);

        $response = $this->getJson('/api/v1/bug-report-configs/myapp');

        $response->assertStatus(200)
            ->assertJsonPath('project', 'myapp')
            ->assertJsonPath('config.framework', 'laravel');
    }

    public function test_show_returns_404_when_not_configured(): void
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/api/v1/bug-report-configs/nonexistent');

        $response->assertStatus(404);
    }

    public function test_config_is_scoped_to_team(): void
    {
        $this->actingAs($this->user);

        // Create config for our team
        BugReportProjectConfig::create([
            'team_id' => $this->team->id,
            'project' => 'myapp',
            'config' => ['framework' => 'laravel'],
        ]);

        // Another team's config with same project name
        $otherTeam = Team::factory()->create();
        BugReportProjectConfig::withoutGlobalScopes()->create([
            'team_id' => $otherTeam->id,
            'project' => 'myapp',
            'config' => ['framework' => 'rails'],
        ]);

        $response = $this->getJson('/api/v1/bug-report-configs/myapp');

        $response->assertStatus(200)
            ->assertJsonPath('config.framework', 'laravel'); // our team's config, not rails
    }

    public function test_upsert_requires_auth(): void
    {
        $response = $this->putJson('/api/v1/bug-report-configs/myapp', [
            'config' => ['test_command' => 'php artisan test'],
        ]);

        $response->assertStatus(401);
    }
}
