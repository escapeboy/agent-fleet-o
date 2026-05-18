<?php

namespace Tests\Unit\Domain\Experiment;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AiRun;
use App\Domain\Agent\Models\SandboxFileActivity;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Experiment\Enums\TimelineActor;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStateTransition;
use App\Domain\Experiment\Services\UnifiedTimelineService;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiedTimelineServiceTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Experiment $experiment;

    private UnifiedTimelineService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UnifiedTimelineService;
        $this->team = Team::factory()->create();
        $this->experiment = Experiment::factory()->create(['team_id' => $this->team->id]);
    }

    private function transition(string $to, ?string $actorId, mixed $at): void
    {
        ExperimentStateTransition::create([
            'team_id' => $this->team->id,
            'experiment_id' => $this->experiment->id,
            'from_state' => 'draft',
            'to_state' => $to,
            'actor_id' => $actorId,
            'created_at' => $at,
        ]);
    }

    private function aiRun(mixed $at): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $run = AiRun::create([
            'team_id' => $this->team->id,
            'agent_id' => $agent->id,
            'experiment_id' => $this->experiment->id,
            'purpose' => 'scoring',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'prompt_snapshot' => [],
        ]);
        $run->forceFill(['created_at' => $at])->save();
    }

    private function sandboxFile(string $path, mixed $at): void
    {
        SandboxFileActivity::create([
            'team_id' => $this->team->id,
            'experiment_id' => $this->experiment->id,
            'path' => $path,
            'operation' => 'created',
            'captured_at' => $at,
        ]);
    }

    public function test_builds_entries_from_a_single_source(): void
    {
        $this->transition('scoring', null, now());

        $entries = $this->service->build($this->experiment);

        $this->assertCount(1, $entries);
        $this->assertSame('transition', $entries->first()->kind);
    }

    public function test_merges_multiple_sources_sorted_descending(): void
    {
        $this->transition('scoring', null, now()->subHours(3));
        $this->aiRun(now()->subHours(2));
        $this->sandboxFile('out.md', now()->subHour());
        ApprovalRequest::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => $this->experiment->id,
        ]);

        $entries = $this->service->build($this->experiment);

        $this->assertSame(
            ['approval', 'sandbox_file', 'ai_run', 'transition'],
            $entries->pluck('kind')->all(),
        );
    }

    public function test_actor_is_human_for_user_triggered_transition(): void
    {
        $user = User::factory()->create();
        $this->transition('scoring', $user->id, now());

        $this->assertSame(TimelineActor::Human, $this->service->build($this->experiment)->first()->actor);
    }

    public function test_actor_is_system_for_transition_and_agent_for_ai_run(): void
    {
        $this->transition('scoring', null, now()->subMinute());
        $this->aiRun(now());

        $byKind = $this->service->build($this->experiment)->keyBy('kind');

        $this->assertSame(TimelineActor::System, $byKind['transition']->actor);
        $this->assertSame(TimelineActor::Agent, $byKind['ai_run']->actor);
    }

    public function test_respects_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->transition('scoring', null, now()->subMinutes($i));
        }

        $this->assertCount(3, $this->service->build($this->experiment, 3));
    }

    public function test_empty_experiment_returns_empty_collection(): void
    {
        $this->assertTrue($this->service->build($this->experiment)->isEmpty());
    }

    public function test_kind_filter_returns_only_matching_entries(): void
    {
        $this->transition('scoring', null, now()->subMinute());
        $this->aiRun(now());

        $entries = $this->service->build($this->experiment, 200, 'ai_run');

        $this->assertCount(1, $entries);
        $this->assertSame('ai_run', $entries->first()->kind);
    }
}
