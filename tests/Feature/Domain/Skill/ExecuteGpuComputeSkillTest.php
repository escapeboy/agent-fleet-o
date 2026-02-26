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

class ExecuteGpuComputeSkillTest extends TestCase
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
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    private function makeSkill(array $config = []): Skill
    {
        return Skill::create([
            'team_id' => $this->team->id,
            'slug' => 'gpu-compute-skill-'.uniqid(),
            'name' => 'GPU Compute Skill',
            'type' => 'gpu_compute',
            'status' => 'active',
            'configuration' => array_merge([
                'provider' => 'runpod',
                'endpoint_id' => 'test-endpoint-123',
            ], $config),
        ]);
    }

    private function storeCredential(string $provider = 'runpod', string $key = 'rpa_testkey12345678'): void
    {
        TeamProviderCredential::create([
            'team_id' => $this->team->id,
            'provider' => $provider,
            'credentials' => ['api_key' => $key],
            'is_active' => true,
        ]);
    }

    public function test_sync_run_returns_output_on_success(): void
    {
        $this->storeCredential();

        Http::fake([
            'https://api.runpod.ai/v2/test-endpoint-123/runsync' => Http::response([
                'id' => 'job-abc',
                'status' => 'COMPLETED',
                'output' => ['result' => 'Hello from GPU', 'score' => 0.99],
            ], 200),
        ]);

        $skill = $this->makeSkill(['use_sync' => true]);

        $result = $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'Run inference'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNotNull($result['output']);
        $this->assertEquals('Hello from GPU', $result['output']['result']);
        $this->assertEquals('completed', $result['execution']->status);
        $this->assertEquals(0, $result['execution']->cost_credits);
    }

    public function test_fails_without_api_key(): void
    {
        // No credential stored
        $skill = $this->makeSkill();

        $result = $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'Hello'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);
        $this->assertStringContainsString("runpod", $result['execution']->error_message);
    }

    public function test_fails_without_endpoint_id(): void
    {
        $this->storeCredential();

        $skill = Skill::create([
            'team_id' => $this->team->id,
            'slug' => 'gpu-no-endpoint-'.uniqid(),
            'name' => 'GPU No Endpoint',
            'type' => 'gpu_compute',
            'status' => 'active',
            'configuration' => ['provider' => 'runpod'], // Missing endpoint_id
        ]);

        $result = $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'Hello'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);
        $this->assertStringContainsString('endpoint_id', $result['execution']->error_message);
    }

    public function test_input_mapping_is_applied(): void
    {
        $this->storeCredential();

        Http::fake([
            'https://api.runpod.ai/v2/test-endpoint-123/runsync' => function ($request) {
                $body = json_decode($request->body(), true);
                if (($body['input']['prompt'] ?? null) === 'Mapped text') {
                    return Http::response(['output' => ['ok' => true]], 200);
                }

                return Http::response(['error' => 'Wrong key name'], 400);
            },
        ]);

        $skill = $this->makeSkill([
            'use_sync' => true,
            'input_mapping' => ['prompt' => 'text'], // endpoint 'prompt' ← skill input 'text'
        ]);

        $result = $this->action->execute(
            skill: $skill,
            input: ['text' => 'Mapped text'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNotNull($result['output']);
        $this->assertEquals('completed', $result['execution']->status);
    }

    public function test_provider_api_error_results_in_failed_execution(): void
    {
        $this->storeCredential();

        Http::fake([
            'https://api.runpod.ai/v2/test-endpoint-123/runsync' => Http::response(
                ['error' => 'Endpoint not found'], 404
            ),
        ]);

        $skill = $this->makeSkill(['use_sync' => true]);

        $result = $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'Hello'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);
        $this->assertNotEmpty($result['execution']->error_message);
    }

    public function test_execution_is_recorded_in_database(): void
    {
        $this->storeCredential();

        Http::fake([
            'https://api.runpod.ai/v2/test-endpoint-123/runsync' => Http::response([
                'output' => ['answer' => 42],
            ], 200),
        ]);

        $skill = $this->makeSkill();

        $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'What is the answer?'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertDatabaseHas('skill_executions', [
            'skill_id' => $skill->id,
            'team_id' => $this->team->id,
            'status' => 'completed',
            'cost_credits' => 0,
        ]);
    }

    public function test_default_provider_is_runpod_when_not_specified(): void
    {
        $this->storeCredential('runpod');

        Http::fake([
            'https://api.runpod.ai/v2/test-endpoint-123/runsync' => Http::response([
                'output' => ['ok' => true],
            ], 200),
        ]);

        // Skill with no explicit provider — should default to runpod
        $skill = Skill::create([
            'team_id' => $this->team->id,
            'slug' => 'gpu-default-provider-'.uniqid(),
            'name' => 'GPU Default Provider',
            'type' => 'gpu_compute',
            'status' => 'active',
            'configuration' => ['endpoint_id' => 'test-endpoint-123'], // No provider key
        ]);

        $result = $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'Hello'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNotNull($result['output']);
        $this->assertEquals('completed', $result['execution']->status);
    }

    public function test_bearer_token_is_sent_in_request(): void
    {
        $this->storeCredential('runpod', 'rpa_my_secret_gpu_key');

        Http::fake([
            'https://api.runpod.ai/v2/test-endpoint-123/runsync' => Http::response([
                'output' => ['ok' => true],
            ], 200),
        ]);

        $skill = $this->makeSkill();

        $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'Hello'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization')
                && $request->header('Authorization')[0] === 'Bearer rpa_my_secret_gpu_key';
        });
    }
}
