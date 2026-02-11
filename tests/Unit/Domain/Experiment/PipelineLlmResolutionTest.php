<?php

namespace Tests\Unit\Domain\Experiment;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Pipeline\RunScoringStage;
use App\Domain\Shared\Models\Team;
use App\Models\GlobalSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineLlmResolutionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'plan' => 'pro',
            'settings' => [],
        ]);
    }

    private function createExperiment(array $constraints = []): Experiment
    {
        return Experiment::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Test Experiment',
            'thesis' => 'Test thesis',
            'track' => ExperimentTrack::Growth,
            'status' => ExperimentStatus::Scoring,
            'constraints' => $constraints,
            'success_criteria' => [],
            'max_iterations' => 3,
            'current_iteration' => 1,
        ]);
    }

    public function test_stage_uses_experiment_llm_config(): void
    {
        $experiment = $this->createExperiment([
            'llm' => [
                'provider' => 'google',
                'model' => 'gemini-2.5-pro',
            ],
        ]);

        $job = new RunScoringStage($experiment->id);
        $method = new \ReflectionMethod($job, 'resolvePipelineLlm');

        $result = $method->invoke($job, $experiment);

        $this->assertEquals('google', $result['provider']);
        $this->assertEquals('gemini-2.5-pro', $result['model']);
    }

    public function test_stage_uses_team_default_when_no_experiment_config(): void
    {
        $this->team->update([
            'settings' => [
                'default_llm_provider' => 'openai',
                'default_llm_model' => 'gpt-4o',
            ],
        ]);

        $experiment = $this->createExperiment();

        $job = new RunScoringStage($experiment->id);
        $method = new \ReflectionMethod($job, 'resolvePipelineLlm');

        $result = $method->invoke($job, $experiment);

        $this->assertEquals('openai', $result['provider']);
        $this->assertEquals('gpt-4o', $result['model']);
    }

    public function test_stage_uses_platform_default_as_last_resort(): void
    {
        // No experiment config, no team settings
        $experiment = $this->createExperiment();

        // Set a GlobalSetting for the platform default
        GlobalSetting::set('default_llm_provider', 'google');
        GlobalSetting::set('default_llm_model', 'gemini-2.5-flash');

        $job = new RunScoringStage($experiment->id);
        $method = new \ReflectionMethod($job, 'resolvePipelineLlm');

        $result = $method->invoke($job, $experiment);

        $this->assertEquals('google', $result['provider']);
        $this->assertEquals('gemini-2.5-flash', $result['model']);
    }
}
