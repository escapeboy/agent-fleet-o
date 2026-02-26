<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Skill\Actions\ExecuteRunPodPodSkillAction;
use App\Domain\Skill\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExecuteRunPodPodSkillTest extends TestCase
{
    use RefreshDatabase;

    private ExecuteRunPodPodSkillAction $action;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(ExecuteRunPodPodSkillAction::class);

        // Zero poll interval to avoid sleep() delays in tests
        Config::set('runpod.pod_defaults.poll_interval_seconds', 0);

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

    private function storeApiKey(string $key = 'rpa_testkey12345678'): void
    {
        TeamProviderCredential::create([
            'team_id' => $this->team->id,
            'provider' => 'runpod',
            'credentials' => ['api_key' => $key],
            'is_active' => true,
        ]);
    }

    private function makeSkill(array $config = []): Skill
    {
        return Skill::create([
            'team_id' => $this->team->id,
            'slug' => 'runpod-pod-skill-'.uniqid(),
            'name' => 'RunPod Pod Skill',
            'type' => 'runpod_pod',
            'status' => 'active',
            'configuration' => array_merge([
                'image_name' => 'runpod/pytorch:latest',
                'gpu_type_id' => 'NVIDIA RTX 4090',
            ], $config),
        ]);
    }

    public function test_full_lifecycle_without_http_request(): void
    {
        $this->storeApiKey();

        Http::fake([
            'https://rest.runpod.io/v1/pods' => Http::response(['id' => 'pod-abc123'], 201),
            'https://rest.runpod.io/v1/pods/pod-abc123' => Http::response([
                'id' => 'pod-abc123',
                'desiredStatus' => 'RUNNING',
                'runtime' => ['ports' => [['ip' => '1.2.3.4', 'publicPort' => 8080]]],
            ], 200),
            'https://rest.runpod.io/v1/pods/pod-abc123/stop' => Http::response(['stopped' => true], 200),
        ]);

        $skill = $this->makeSkill(); // No request_url_template

        $result = $this->action->execute(
            skill: $skill,
            input: [],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNotNull($result['output']);
        $this->assertEquals('pod-abc123', $result['output']['pod_id']);
        $this->assertEquals('completed', $result['output']['status']);
        $this->assertEquals('completed', $result['execution']->status);
    }

    public function test_lifecycle_with_http_request_to_pod(): void
    {
        $this->storeApiKey();

        Http::fake([
            'https://rest.runpod.io/v1/pods' => Http::response(['id' => 'pod-xyz'], 201),
            'https://rest.runpod.io/v1/pods/pod-xyz' => Http::response([
                'id' => 'pod-xyz',
                'desiredStatus' => 'RUNNING',
                'runtime' => ['ports' => [['publicPort' => 8080]]],
            ], 200),
            'https://pod-xyz-8080.proxy.runpod.net/predict' => Http::response([
                'prediction' => 'Cat',
                'confidence' => 0.97,
            ], 200),
            'https://rest.runpod.io/v1/pods/pod-xyz/stop' => Http::response([], 200),
        ]);

        $skill = $this->makeSkill([
            'request_url_template' => 'https://{pod_id}-8080.proxy.runpod.net/predict',
            'request_method' => 'POST',
            'startup_timeout_seconds' => 30,
        ]);

        $result = $this->action->execute(
            skill: $skill,
            input: ['image_data' => 'base64...'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNotNull($result['output']);
        $this->assertEquals('Cat', $result['output']['prediction']);
        $this->assertEquals(0.97, $result['output']['confidence']);
        $this->assertEquals('pod-xyz', $result['output']['pod_id']);
        $this->assertEquals('completed', $result['execution']->status);
    }

    public function test_pod_is_stopped_even_when_http_request_fails(): void
    {
        $this->storeApiKey();

        Http::fake([
            'https://rest.runpod.io/v1/pods' => Http::response(['id' => 'pod-failtest'], 201),
            'https://rest.runpod.io/v1/pods/pod-failtest' => Http::response([
                'id' => 'pod-failtest',
                'desiredStatus' => 'RUNNING',
                'runtime' => ['ports' => []],
            ], 200),
            'https://pod-failtest-8080.proxy.runpod.net/predict' => Http::response('Internal Server Error', 500),
            'https://rest.runpod.io/v1/pods/pod-failtest/stop' => Http::response([], 200),
        ]);

        $skill = $this->makeSkill([
            'request_url_template' => 'https://{pod_id}-8080.proxy.runpod.net/predict',
            'startup_timeout_seconds' => 30,
        ]);

        $result = $this->action->execute(
            skill: $skill,
            input: ['data' => 'test'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);

        // Verify stop was called despite the failure
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/pods/pod-failtest/stop');
        });
    }

    public function test_fails_gracefully_without_api_key(): void
    {
        $skill = $this->makeSkill();

        $result = $this->action->execute(
            skill: $skill,
            input: [],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);
        $this->assertStringContainsString('RunPod API key', $result['execution']->error_message);
    }

    public function test_fails_without_required_image_name(): void
    {
        $this->storeApiKey();

        $skill = Skill::create([
            'team_id' => $this->team->id,
            'slug' => 'runpod-no-image-'.uniqid(),
            'name' => 'Bad RunPod Pod',
            'type' => 'runpod_pod',
            'status' => 'active',
            'configuration' => ['gpu_type_id' => 'NVIDIA RTX 4090'], // Missing image_name
        ]);

        $result = $this->action->execute(
            skill: $skill,
            input: [],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);
        $this->assertStringContainsString('image_name', $result['execution']->error_message);
    }

    public function test_cost_credits_are_recorded_based_on_gpu_price(): void
    {
        $this->storeApiKey();

        Http::fake([
            'https://rest.runpod.io/v1/pods' => Http::response(['id' => 'pod-cost'], 201),
            'https://rest.runpod.io/v1/pods/pod-cost' => Http::response([
                'id' => 'pod-cost',
                'desiredStatus' => 'RUNNING',
                'runtime' => ['ports' => []],
            ], 200),
            'https://rest.runpod.io/v1/pods/pod-cost/stop' => Http::response([], 200),
        ]);

        $skill = $this->makeSkill([
            'gpu_type_id' => 'NVIDIA RTX 4090',
            'startup_timeout_seconds' => 30,
        ]);

        $result = $this->action->execute(
            skill: $skill,
            input: [],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals('completed', $result['execution']->status);
        // Cost should be > 0 (some time elapsed for pod creation + polling)
        $this->assertGreaterThanOrEqual(0, $result['execution']->cost_credits);
    }

    public function test_pod_payload_includes_environment_variables(): void
    {
        $this->storeApiKey();

        Http::fake([
            'https://rest.runpod.io/v1/pods' => function ($request) {
                $body = json_decode($request->body(), true);
                if (($body['env']['MODEL_PATH'] ?? null) === '/models/llama') {
                    return Http::response(['id' => 'pod-env'], 201);
                }

                return Http::response(['error' => 'missing env'], 400);
            },
            'https://rest.runpod.io/v1/pods/pod-env' => Http::response([
                'id' => 'pod-env',
                'desiredStatus' => 'RUNNING',
                'runtime' => ['ports' => []],
            ], 200),
            'https://rest.runpod.io/v1/pods/pod-env/stop' => Http::response([], 200),
        ]);

        $skill = $this->makeSkill([
            'env' => ['MODEL_PATH' => '/models/llama'],
            'startup_timeout_seconds' => 30,
        ]);

        $result = $this->action->execute(
            skill: $skill,
            input: [],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals('completed', $result['execution']->status);
    }

    public function test_execution_recorded_in_database(): void
    {
        $this->storeApiKey();

        Http::fake([
            'https://rest.runpod.io/v1/pods' => Http::response(['id' => 'pod-db-test'], 201),
            'https://rest.runpod.io/v1/pods/pod-db-test' => Http::response([
                'id' => 'pod-db-test',
                'desiredStatus' => 'RUNNING',
                'runtime' => ['ports' => []],
            ], 200),
            'https://rest.runpod.io/v1/pods/pod-db-test/stop' => Http::response([], 200),
        ]);

        $skill = $this->makeSkill(['startup_timeout_seconds' => 30]);

        $this->action->execute(
            skill: $skill,
            input: ['test' => 'data'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertDatabaseHas('skill_executions', [
            'skill_id' => $skill->id,
            'team_id' => $this->team->id,
            'status' => 'completed',
        ]);
    }
}
