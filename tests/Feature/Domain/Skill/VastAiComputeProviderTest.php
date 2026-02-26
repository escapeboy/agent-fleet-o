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

class VastAiComputeProviderTest extends TestCase
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
            'slug' => 'test-team-vast',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        TeamProviderCredential::create([
            'team_id' => $this->team->id,
            'provider' => 'vast',
            'credentials' => ['api_key' => 'vast_testkey_12345'],
            'is_active' => true,
        ]);
    }

    private function makeSkill(array $config = []): Skill
    {
        return Skill::create([
            'team_id' => $this->team->id,
            'slug' => 'vast-skill-'.uniqid(),
            'name' => 'Vast.ai Skill',
            'type' => 'gpu_compute',
            'status' => 'active',
            'configuration' => array_merge([
                'provider' => 'vast',
                'endpoint_id' => 'my-llm-endpoint',
                'use_sync' => true,
                'route_path' => '/v1/chat/completions',
            ], $config),
        ]);
    }

    public function test_two_step_route_and_inference(): void
    {
        Http::fake([
            'https://run.vast.ai/route/' => Http::response([
                'url' => 'https://worker-12345.vast.ai',
            ], 200),
            'https://worker-12345.vast.ai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Hello!']]],
            ], 200),
        ]);

        $skill = $this->makeSkill();

        $result = $this->action->execute(
            skill: $skill,
            input: ['messages' => [['role' => 'user', 'content' => 'Hello']]],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals('completed', $result['execution']->status);
        $this->assertArrayHasKey('choices', $result['output']);
    }

    public function test_endpoint_name_sent_in_route_request(): void
    {
        Http::fake([
            'https://run.vast.ai/route/' => function ($request) {
                $body = json_decode($request->body(), true);
                if (($body['endpoint'] ?? null) === 'my-llm-endpoint') {
                    return Http::response(['url' => 'https://worker.vast.ai'], 200);
                }

                return Http::response(['error' => 'Wrong endpoint'], 400);
            },
            'https://worker.vast.ai/*' => Http::response(['result' => 'ok'], 200),
        ]);

        $skill = $this->makeSkill(['route_path' => '/run']);

        $result = $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'test'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals('completed', $result['execution']->status);
    }

    public function test_route_failure_results_in_failed_execution(): void
    {
        Http::fake([
            'https://run.vast.ai/route/' => Http::response(['error' => 'No workers available'], 503),
        ]);

        $skill = $this->makeSkill();

        $result = $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'test'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);
        $this->assertStringContainsString('503', $result['execution']->error_message);
    }

    public function test_worker_failure_results_in_failed_execution(): void
    {
        Http::fake([
            'https://run.vast.ai/route/' => Http::response(['url' => 'https://worker.vast.ai'], 200),
            'https://worker.vast.ai/*' => Http::response(['error' => 'OOM'], 500),
        ]);

        $skill = $this->makeSkill(['route_path' => '/infer']);

        $result = $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'test'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);
    }

    public function test_bearer_auth_sent_to_route_and_worker(): void
    {
        Http::fake([
            'https://run.vast.ai/route/' => Http::response(['url' => 'https://worker.vast.ai'], 200),
            'https://worker.vast.ai/*' => Http::response(['ok' => true], 200),
        ]);

        $skill = $this->makeSkill(['route_path' => '/run']);

        $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'test'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization')
                && $request->header('Authorization')[0] === 'Bearer vast_testkey_12345';
        });
    }

    public function test_cost_credits_are_zero(): void
    {
        Http::fake([
            'https://run.vast.ai/route/' => Http::response(['url' => 'https://worker.vast.ai'], 200),
            'https://worker.vast.ai/*' => Http::response(['result' => 'done'], 200),
        ]);

        $skill = $this->makeSkill(['route_path' => '/run']);

        $result = $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'test'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals(0, $result['execution']->cost_credits);
    }
}
