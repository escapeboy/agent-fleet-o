<?php

namespace Tests\Feature\Domain\Experiment;

use App\Domain\Experiment\Actions\SteerExperimentAction;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SteerExperimentActionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Steer Test Team',
            'slug' => 'steer-test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($this->user);
    }

    private function makeExperiment(array $overrides = []): Experiment
    {
        return Experiment::create(array_merge([
            'team_id' => $this->team->id,
            'title' => 'Steer Target',
            'status' => 'executing',
            'track' => 'growth',
            'description' => 'test',
            'user_id' => $this->user->id,
            'initiated_by_user_id' => $this->user->id,
        ], $overrides));
    }

    public function test_stores_message_in_orchestration_config(): void
    {
        $experiment = $this->makeExperiment();

        $result = app(SteerExperimentAction::class)->execute(
            experiment: $experiment,
            message: 'Use staging DB, not prod',
            userId: $this->user->id,
        );

        $this->assertSame('Use staging DB, not prod', $result->orchestration_config['steering_message']);
        $this->assertSame($this->user->id, $result->orchestration_config['steering_queued_by']);
        $this->assertArrayHasKey('steering_queued_at', $result->orchestration_config);
    }

    public function test_overwrites_previously_queued_message(): void
    {
        $experiment = $this->makeExperiment([
            'orchestration_config' => ['steering_message' => 'old'],
        ]);

        $result = app(SteerExperimentAction::class)->execute(
            experiment: $experiment,
            message: 'new instruction',
        );

        $this->assertSame('new instruction', $result->orchestration_config['steering_message']);
    }

    public function test_rejects_empty_message(): void
    {
        $experiment = $this->makeExperiment();

        $this->expectException(\InvalidArgumentException::class);

        app(SteerExperimentAction::class)->execute(
            experiment: $experiment,
            message: '   ',
        );
    }

    public function test_truncates_messages_over_2000_chars(): void
    {
        $experiment = $this->makeExperiment();
        $longMessage = str_repeat('a', 5000);

        $result = app(SteerExperimentAction::class)->execute(
            experiment: $experiment,
            message: $longMessage,
        );

        $this->assertSame(2000, mb_strlen($result->orchestration_config['steering_message']));
    }

    public function test_preserves_existing_orchestration_config_keys(): void
    {
        $experiment = $this->makeExperiment([
            'orchestration_config' => ['custom_setting' => 'keep_me'],
        ]);

        $result = app(SteerExperimentAction::class)->execute(
            experiment: $experiment,
            message: 'steer',
        );

        $this->assertSame('keep_me', $result->orchestration_config['custom_setting']);
        $this->assertSame('steer', $result->orchestration_config['steering_message']);
    }
}
