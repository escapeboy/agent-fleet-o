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

class FalComputeProviderTest extends TestCase
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
            'slug' => 'test-team-fal',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        TeamProviderCredential::create([
            'team_id' => $this->team->id,
            'provider' => 'fal',
            'credentials' => ['api_key' => 'fal_testkey:secret123'],
            'is_active' => true,
        ]);
    }

    private function makeSkill(array $config = []): Skill
    {
        return Skill::create([
            'team_id' => $this->team->id,
            'slug' => 'fal-skill-'.uniqid(),
            'name' => 'Fal Skill',
            'type' => 'gpu_compute',
            'status' => 'active',
            'configuration' => array_merge([
                'provider' => 'fal',
                'endpoint_id' => 'fal-ai/flux/dev',
                'use_sync' => true,
            ], $config),
        ]);
    }

    public function test_sync_run_returns_output(): void
    {
        Http::fake([
            'https://fal.run/fal-ai/flux/dev' => Http::response([
                'images' => [['url' => 'https://fal.media/result.png', 'width' => 1024, 'height' => 1024]],
            ], 200),
        ]);

        $skill = $this->makeSkill(['use_sync' => true]);

        $result = $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'A futuristic city'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals('completed', $result['execution']->status);
        $this->assertArrayHasKey('images', $result['output']);
    }

    public function test_fal_uses_key_auth_header_not_bearer(): void
    {
        Http::fake([
            'https://fal.run/*' => Http::response(['images' => []], 200),
        ]);

        $skill = $this->makeSkill(['use_sync' => true]);

        $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'test'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        Http::assertSent(function ($request) {
            $authHeader = $request->header('Authorization')[0] ?? '';

            return str_starts_with($authHeader, 'Key ') && ! str_starts_with($authHeader, 'Bearer ');
        });
    }

    public function test_async_run_submits_to_queue_and_polls(): void
    {
        Http::fake([
            // Submit to queue
            'https://queue.fal.run/fal-ai/flux/dev' => Http::response([
                'request_id' => 'req-abc-123',
                'status' => 'IN_QUEUE',
            ], 200),
            // Status check
            'https://queue.fal.run/fal-ai/flux/dev/requests/req-abc-123/status' => Http::response([
                'status' => 'COMPLETED',
            ], 200),
            // Get result
            'https://queue.fal.run/fal-ai/flux/dev/requests/req-abc-123' => Http::response([
                'response' => ['images' => [['url' => 'https://fal.media/async-result.png']]],
            ], 200),
        ]);

        $skill = $this->makeSkill(['use_sync' => false, 'timeout_seconds' => 30]);

        $result = $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'Async test'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals('completed', $result['execution']->status);
        $this->assertArrayHasKey('images', $result['output']);
    }

    public function test_fal_api_error_results_in_failed_execution(): void
    {
        Http::fake([
            'https://fal.run/*' => Http::response(['error' => 'Model not found'], 404),
        ]);

        $skill = $this->makeSkill(['use_sync' => true]);

        $result = $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'test'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);
    }

    public function test_cost_credits_are_zero(): void
    {
        Http::fake([
            'https://fal.run/*' => Http::response(['result' => 'done'], 200),
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
