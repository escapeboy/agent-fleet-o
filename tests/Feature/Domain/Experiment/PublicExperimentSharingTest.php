<?php

namespace Tests\Feature\Domain\Experiment;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicExperimentSharingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable Vite manifest in tests (views use @vite directive)
        $this->withoutVite();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    private function makeExperiment(array $attributes = []): Experiment
    {
        return Experiment::factory()->create(array_merge([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
        ], $attributes));
    }

    public function test_share_token_generation_creates_unique_token(): void
    {
        $experiment1 = $this->makeExperiment();
        $experiment2 = $this->makeExperiment();

        $token1 = $experiment1->generateShareToken();
        $token2 = $experiment2->generateShareToken();

        $this->assertNotEmpty($token1);
        $this->assertNotEmpty($token2);
        $this->assertNotEquals($token1, $token2);

        $experiment1->refresh();
        $this->assertEquals($token1, $experiment1->share_token);
        $this->assertTrue($experiment1->share_enabled);
    }

    public function test_public_experiment_page_returns_200_for_valid_token(): void
    {
        $experiment = $this->makeExperiment(['title' => 'My Public Experiment']);
        $experiment->generateShareToken();

        $response = $this->get('/share/'.$experiment->share_token);

        $response->assertStatus(200);
        $response->assertSee('My Public Experiment');
    }

    public function test_public_experiment_page_returns_404_for_invalid_token(): void
    {
        $response = $this->get('/share/invalid-nonexistent-token');

        $response->assertStatus(404);
    }

    public function test_public_experiment_page_returns_404_when_sharing_disabled(): void
    {
        $experiment = $this->makeExperiment();
        $experiment->generateShareToken();
        $experiment->update(['share_enabled' => false]);

        $response = $this->get('/share/'.$experiment->share_token);

        $response->assertStatus(404);
    }

    public function test_public_experiment_page_returns_404_when_share_expired(): void
    {
        $experiment = $this->makeExperiment();
        $experiment->generateShareToken();
        $experiment->update([
            'share_config' => array_merge($experiment->share_config ?? [], [
                'expires_at' => now()->subHour()->toIso8601String(),
            ]),
        ]);

        $response = $this->get('/share/'.$experiment->share_token);

        $response->assertStatus(404);
    }

    public function test_sensitive_fields_are_hidden_in_public_view(): void
    {
        $experiment = $this->makeExperiment([
            'constraints' => ['max_cost' => 100],
            'success_criteria' => ['metric' => 'conversion'],
        ]);
        $experiment->generateShareToken();

        $response = $this->get('/share/'.$experiment->share_token);

        $response->assertStatus(200);
        // Internal JSONB config fields should not be rendered in the view
        // (setHidden is applied in the controller)
        $response->assertDontSee('orchestration_config');
        // JSON key for internal fields must not appear in raw form
        $response->assertDontSee('"success_criteria"');
    }
}
