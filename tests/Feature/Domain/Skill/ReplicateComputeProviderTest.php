<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Skill\Actions\ExecuteGpuComputeSkillAction;
use App\Domain\Skill\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReplicateComputeProviderTest extends TestCase
{
    use RefreshDatabase;

    private ExecuteGpuComputeSkillAction $action;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(ExecuteGpuComputeSkillAction::class);

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-replicate',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        TeamProviderCredential::create([
            'team_id' => $this->team->id,
            'provider' => 'replicate',
            'credentials' => ['api_key' => 'r8_test_key_12345'],
            'is_active' => true,
        ]);
    }

    private function makeSkill(array $config = []): Skill
    {
        return Skill::create([
            'team_id' => $this->team->id,
            'slug' => 'replicate-skill-'.uniqid(),
            'name' => 'Replicate Skill',
            'type' => 'gpu_compute',
            'status' => 'active',
            'configuration' => array_merge([
                'provider' => 'replicate',
                'endpoint_id' => 'stability-ai/sdxl:1.0.0',
                'use_sync' => true,
            ], $config),
        ]);
    }

    public function test_sync_run_completes_within_wait_window(): void
    {
        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response([
                'id' => 'pred-abc123',
                'status' => 'succeeded',
                'output' => ['https://replicate.delivery/pbxt/image.png'],
            ], 200),
        ]);

        $skill = $this->makeSkill(['use_sync' => true]);

        $result = $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'A cat on a skateboard'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals('completed', $result['execution']->status);
        $this->assertNotNull($result['output']);
        $this->assertContains('https://replicate.delivery/pbxt/image.png', $result['output']);
    }

    public function test_sync_run_falls_back_to_polling_when_not_immediate(): void
    {
        Http::fake([
            // First call: prediction still starting
            'https://api.replicate.com/v1/predictions' => Http::response([
                'id' => 'pred-poll-123',
                'status' => 'starting',
                'output' => null,
            ], 200),
            // Poll: completed
            'https://api.replicate.com/v1/predictions/pred-poll-123' => Http::response([
                'id' => 'pred-poll-123',
                'status' => 'succeeded',
                'output' => ['result'],
            ], 200),
        ]);

        $skill = $this->makeSkill(['use_sync' => true, 'timeout_seconds' => 30]);

        $result = $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'test'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals('completed', $result['execution']->status);
    }

    public function test_prefer_wait_header_is_sent(): void
    {
        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response([
                'id' => 'pred-header-test',
                'status' => 'succeeded',
                'output' => ['done'],
            ], 200),
        ]);

        $skill = $this->makeSkill(['use_sync' => true]);

        $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'test'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.replicate.com')
                && $request->hasHeader('Prefer')
                && str_starts_with($request->header('Prefer')[0], 'wait=');
        });
    }

    public function test_prediction_version_sent_correctly(): void
    {
        Http::fake([
            'https://api.replicate.com/v1/predictions' => function ($request) {
                $body = json_decode($request->body(), true);
                if (($body['version'] ?? null) === 'stability-ai/sdxl:1.0.0') {
                    return Http::response([
                        'id' => 'pred-version-check',
                        'status' => 'succeeded',
                        'output' => ['ok'],
                    ], 200);
                }

                return Http::response(['error' => 'Wrong version'], 400);
            },
        ]);

        $skill = $this->makeSkill(['use_sync' => true]);

        $result = $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'test'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals('completed', $result['execution']->status);
    }

    public function test_failed_prediction_results_in_failed_execution(): void
    {
        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response([
                'id' => 'pred-fail-123',
                'status' => 'failed',
                'error' => 'CUDA out of memory',
                'output' => null,
            ], 200),
        ]);

        $skill = $this->makeSkill(['use_sync' => true]);

        $result = $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'A massive image'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);
        $this->assertStringContainsString('failed', strtolower($result['execution']->error_message));
    }

    public function test_cost_credits_are_zero(): void
    {
        Http::fake([
            'https://api.replicate.com/v1/predictions' => Http::response([
                'id' => 'pred-cost',
                'status' => 'succeeded',
                'output' => ['result'],
            ], 200),
        ]);

        $skill = $this->makeSkill(['use_sync' => true]);

        $result = $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'test'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals(0, $result['execution']->cost_credits);
    }
}
