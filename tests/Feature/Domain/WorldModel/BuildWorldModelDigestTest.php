<?php

namespace Tests\Feature\Domain\WorldModel;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use App\Domain\WorldModel\Actions\BuildWorldModelDigestAction;
use App\Domain\WorldModel\Models\TeamWorldModel;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BuildWorldModelDigestTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'WM Team',
            'slug' => 'wm-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
    }

    public function test_builds_digest_from_recent_data(): void
    {
        Signal::factory()->count(2)->create([
            'team_id' => $this->team->id,
        ]);
        Experiment::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Ship faster',
            'thesis' => 'reduce deploy time',
            'status' => ExperimentStatus::Completed,
        ]);

        $this->bindGateway('## Current focus\nShip faster.\n\n## Recent signals\nTwo webhook events.\n\n## Recent outcomes\nOne experiment completed.\n\n## Watchlist / risks\nNone observed in the window.');

        $record = app(BuildWorldModelDigestAction::class)->execute($this->team);

        $this->assertInstanceOf(TeamWorldModel::class, $record);
        $this->assertStringContainsString('Current focus', $record->digest ?? '');
        $this->assertSame(2, $record->stats['signal_count']);
        $this->assertSame(1, $record->stats['experiment_count']);
        $this->assertNotNull($record->generated_at);
    }

    public function test_skips_llm_when_no_data_in_window(): void
    {
        $gatewaySpy = Mockery::mock(AiGatewayInterface::class);
        $gatewaySpy->shouldNotReceive('complete');
        $this->app->instance(AiGatewayInterface::class, $gatewaySpy);

        $record = app(BuildWorldModelDigestAction::class)->execute($this->team);

        $this->assertNull($record->digest);
        $this->assertSame('no data in window', $record->stats['skipped'] ?? null);
    }

    public function test_update_or_create_overwrites_existing_record(): void
    {
        TeamWorldModel::create([
            'team_id' => $this->team->id,
            'digest' => 'OLD',
            'stats' => [],
            'generated_at' => now()->subDays(10),
        ]);

        Signal::factory()->create(['team_id' => $this->team->id]);
        $this->bindGateway('NEW DIGEST');

        app(BuildWorldModelDigestAction::class)->execute($this->team);

        $this->assertSame(1, TeamWorldModel::where('team_id', $this->team->id)->count());
        $fresh = TeamWorldModel::where('team_id', $this->team->id)->first();
        $this->assertSame('NEW DIGEST', $fresh->digest);
    }

    private function bindGateway(string $content): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(new AiResponseDTO(
            content: $content,
            parsedOutput: null,
            usage: new AiUsageDTO(200, 100, 0.05),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            latencyMs: 42,
            schemaValid: true,
            cached: false,
        ));
        $this->app->instance(AiGatewayInterface::class, $gateway);
    }
}
