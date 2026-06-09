<?php

namespace Tests\Feature\Livewire;

use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Enums\AnnotationRating;
use App\Domain\Skill\Enums\BenchmarkStatus;
use App\Domain\Skill\Jobs\SkillImprovementIterationJob;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillBenchmark;
use App\Domain\Skill\Models\SkillVersion;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Livewire\Skills\SkillOpsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SkillOpsPageTest extends TestCase
{
    use RefreshDatabase;

    private function loggedInOwner(): User
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Ops Test',
            'slug' => 'ops-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $team->users()->attach($user, ['role' => TeamRole::Owner->value]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);

        return $user;
    }

    private function bindFakeGateway(): void
    {
        $gateway = new class implements AiGatewayInterface
        {
            public function complete(AiRequestDTO $request): AiResponseDTO
            {
                return new AiResponseDTO(
                    content: 'faked',
                    parsedOutput: null,
                    usage: new AiUsageDTO(promptTokens: 1, completionTokens: 1, costCredits: 1),
                    provider: 'anthropic',
                    model: 'claude-sonnet-4-5',
                    latencyMs: 1,
                );
            }

            public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
            {
                return $this->complete($request);
            }

            public function estimateCost(AiRequestDTO $request): int
            {
                return 1;
            }
        };

        $this->app->instance(AiGatewayInterface::class, $gateway);
    }

    public function test_lists_only_current_teams_skills(): void
    {
        $user = $this->loggedInOwner();

        $mine = Skill::factory()->create(['team_id' => $user->currentTeam->id, 'name' => 'My Skill']);
        $other = Skill::factory()->create(['name' => 'Foreign Skill']);

        Livewire::test(SkillOpsPage::class)
            ->assertSee('My Skill')
            ->assertDontSee('Foreign Skill');
    }

    public function test_start_loop_requires_authorization(): void
    {
        $user = $this->loggedInOwner();
        Skill::factory()->create(['team_id' => $user->currentTeam->id]);

        Gate::define('edit-content', fn () => false);

        Livewire::test(SkillOpsPage::class)
            ->call('startLoop')
            ->assertForbidden();
    }

    public function test_start_loop_creates_benchmark_and_dispatches_job(): void
    {
        Queue::fake();
        $user = $this->loggedInOwner();
        $skill = Skill::factory()->create(['team_id' => $user->currentTeam->id]);

        Livewire::test(SkillOpsPage::class)
            ->set('tab', 'loop')
            ->set('skillId', $skill->id)
            ->set('loopMetric', 'accuracy')
            ->set('loopMaxIterations', 3)
            ->call('startLoop')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('skill_benchmarks', [
            'skill_id' => $skill->id,
            'metric_name' => 'accuracy',
            'status' => BenchmarkStatus::Running->value,
        ]);

        Queue::assertPushed(SkillImprovementIterationJob::class);
    }

    public function test_start_benchmark_creates_running_benchmark(): void
    {
        Queue::fake();
        $this->bindFakeGateway();
        $user = $this->loggedInOwner();
        $skill = Skill::factory()->create(['team_id' => $user->currentTeam->id]);
        SkillVersion::factory()->create(['skill_id' => $skill->id, 'version' => 1]);

        Livewire::test(SkillOpsPage::class)
            ->set('tab', 'benchmarks')
            ->set('benchSkillId', $skill->id)
            ->set('benchMetricName', 'latency_ms')
            ->set('benchTestInputs', '[{"input":"hi"}]')
            ->call('startBenchmark')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('skill_benchmarks', [
            'skill_id' => $skill->id,
            'metric_name' => 'latency_ms',
            'status' => BenchmarkStatus::Running->value,
        ]);
    }

    public function test_cancel_benchmark_marks_cancelled(): void
    {
        $user = $this->loggedInOwner();
        $skill = Skill::factory()->create(['team_id' => $user->currentTeam->id]);
        $benchmark = SkillBenchmark::create([
            'skill_id' => $skill->id,
            'team_id' => $user->currentTeam->id,
            'metric_name' => 'accuracy',
            'metric_direction' => 'maximize',
            'baseline_value' => 0.0,
            'best_value' => 0.0,
            'test_inputs' => [],
            'iteration_count' => 0,
            'max_iterations' => 5,
            'time_budget_seconds' => 600,
            'iteration_budget_seconds' => 60,
            'complexity_penalty' => 0.0,
            'improvement_threshold' => 0.0,
            'status' => BenchmarkStatus::Running,
            'started_at' => now(),
            'settings' => [],
        ]);

        Livewire::test(SkillOpsPage::class)
            ->call('cancelBenchmark', $benchmark->id);

        $this->assertEquals(BenchmarkStatus::Cancelled, $benchmark->fresh()->status);
    }

    public function test_submit_annotation_persists_rating(): void
    {
        $user = $this->loggedInOwner();
        $skill = Skill::factory()->create(['team_id' => $user->currentTeam->id]);
        $version = SkillVersion::factory()->create(['skill_id' => $skill->id, 'version' => 1]);

        Livewire::test(SkillOpsPage::class)
            ->set('tab', 'annotations')
            ->set('annotateVersionId', $version->id)
            ->set('annotateModelId', 'anthropic/claude-sonnet-4-5')
            ->set('annotateInput', 'test input')
            ->set('annotateOutput', 'test output')
            ->set('annotateRating', 'good')
            ->call('submitAnnotation')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('skill_annotations', [
            'skill_version_id' => $version->id,
            'team_id' => $user->currentTeam->id,
            'rating' => AnnotationRating::Good->value,
        ]);
    }

    public function test_submit_annotation_requires_authorization(): void
    {
        $user = $this->loggedInOwner();
        $skill = Skill::factory()->create(['team_id' => $user->currentTeam->id]);
        $version = SkillVersion::factory()->create(['skill_id' => $skill->id, 'version' => 1]);

        Gate::define('edit-content', fn () => false);

        Livewire::test(SkillOpsPage::class)
            ->set('annotateVersionId', $version->id)
            ->set('annotateModelId', 'anthropic/claude-sonnet-4-5')
            ->set('annotateInput', 'x')
            ->set('annotateOutput', 'y')
            ->set('annotateRating', 'good')
            ->call('submitAnnotation')
            ->assertForbidden();

        $this->assertDatabaseCount('skill_annotations', 0);
    }
}
