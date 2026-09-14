<?php

namespace Tests\Feature\Domain\Experiment;

use App\Domain\Audit\Models\AuditEntry;
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

    public function test_stores_message_in_steering_queue(): void
    {
        $experiment = $this->makeExperiment();

        $result = app(SteerExperimentAction::class)->execute(
            experiment: $experiment,
            message: 'Use staging DB, not prod',
            userId: $this->user->id,
        );

        $queue = $result->orchestration_config['steering_queue'];
        $this->assertCount(1, $queue);
        $this->assertSame('Use staging DB, not prod', $queue[0]['message']);
        $this->assertSame($this->user->id, $queue[0]['queued_by']);
        $this->assertArrayHasKey('queued_at', $queue[0]);
        $this->assertNotEmpty($queue[0]['id']);
    }

    public function test_appends_to_queue_in_order(): void
    {
        $experiment = $this->makeExperiment();
        $action = app(SteerExperimentAction::class);

        $action->execute(experiment: $experiment, message: 'first');
        $result = $action->execute(experiment: $experiment->fresh(), message: 'second');

        $this->assertSame(['first', 'second'], array_column($result->orchestration_config['steering_queue'], 'message'));
    }

    public function test_legacy_single_message_counts_as_pending(): void
    {
        $experiment = $this->makeExperiment([
            'orchestration_config' => ['steering_message' => 'old style'],
        ]);

        $result = app(SteerExperimentAction::class)->execute(experiment: $experiment, message: 'new style');

        $pending = SteerExperimentAction::pendingQueue($result->orchestration_config);
        $this->assertSame(['old style', 'new style'], array_column($pending, 'message'));
        $this->assertSame('legacy', $pending[0]['id']);
    }

    public function test_rejects_when_queue_is_full(): void
    {
        $experiment = $this->makeExperiment();
        $action = app(SteerExperimentAction::class);

        for ($i = 0; $i < SteerExperimentAction::MAX_PENDING; $i++) {
            $action->execute(experiment: $experiment->fresh(), message: "msg {$i}");
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Steering queue is full');

        $action->execute(experiment: $experiment->fresh(), message: 'one too many');
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

        $this->assertSame(2000, mb_strlen($result->orchestration_config['steering_queue'][0]['message']));
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
        $this->assertSame('steer', $result->orchestration_config['steering_queue'][0]['message']);
    }

    public function test_writes_audit_entry_when_queued(): void
    {
        $experiment = $this->makeExperiment();

        $result = app(SteerExperimentAction::class)->execute(
            experiment: $experiment,
            message: 'audit-traced message',
            userId: $this->user->id,
        );

        $entry = AuditEntry::where('event', 'experiment.steering_queued')
            ->where('subject_id', $experiment->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($this->user->id, $entry->user_id);
        $this->assertSame(20, $entry->properties['message_length'] ?? null);
        $this->assertSame(1, $entry->properties['queue_length'] ?? null);
        $this->assertSame($result->orchestration_config['steering_queue'][0]['id'], $entry->properties['steering_id'] ?? null);
    }
}
