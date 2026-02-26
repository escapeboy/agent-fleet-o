<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Skill\Actions\ExecuteRunPodSkillAction;
use App\Domain\Skill\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExecuteRunPodSkillTest extends TestCase
{
    use RefreshDatabase;

    private ExecuteRunPodSkillAction $action;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(ExecuteRunPodSkillAction::class);

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
            'slug' => 'runpod-skill-'.uniqid(),
            'name' => 'RunPod Skill',
            'type' => 'runpod_endpoint',
            'status' => 'active',
            'configuration' => array_merge(['endpoint_id' => 'test-endpoint-123'], $config),
        ]);
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

    public function test_sync_run_returns_output_on_success(): void
    {
        $this->storeApiKey();

        Http::fake([
            'https://api.runpod.ai/v2/test-endpoint-123/runsync' => Http::response([
                'id' => 'job-abc',
                'status' => 'COMPLETED',
                'output' => ['result' => 'Hello from RunPod', 'tokens' => 42],
            ], 200),
        ]);

        $skill = $this->makeSkill(['use_sync' => true]);

        $result = $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'Hello'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNotNull($result['output']);
        $this->assertEquals('Hello from RunPod', $result['output']['result']);
        $this->assertEquals(42, $result['output']['tokens']);
        $this->assertEquals('completed', $result['execution']->status);
        $this->assertEquals(0, $result['execution']->cost_credits);
    }

    public function test_fails_gracefully_without_api_key(): void
    {
        // No TeamProviderCredential for runpod
        $skill = $this->makeSkill();

        $result = $this->action->execute(
            skill: $skill,
            input: ['prompt' => 'Hello'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);
        $this->assertStringContainsString('RunPod API key', $result['execution']->error_message);
    }

    public function test_fails_gracefully_without_endpoint_id(): void
    {
        $this->storeApiKey();

        $skill = Skill::create([
            'team_id' => $this->team->id,
            'slug' => 'runpod-no-endpoint-'.uniqid(),
            'name' => 'RunPod No Endpoint',
            'type' => 'runpod_endpoint',
            'status' => 'active',
            'configuration' => [], // Missing endpoint_id
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
        $this->storeApiKey();

        Http::fake([
            'https://api.runpod.ai/v2/test-endpoint-123/runsync' => function ($request) {
                $body = json_decode($request->body(), true);
                // Verify the mapping was applied: skill input 'text' → endpoint 'prompt'
                if (($body['input']['prompt'] ?? null) === 'Mapped input') {
                    return Http::response(['output' => ['ok' => true]], 200);
                }

                return Http::response(['error' => 'Wrong mapping'], 400);
            },
        ]);

        $skill = $this->makeSkill([
            'use_sync' => true,
            'input_mapping' => ['prompt' => 'text'], // endpoint key → skill input key
        ]);

        $result = $this->action->execute(
            skill: $skill,
            input: ['text' => 'Mapped input'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNotNull($result['output']);
        $this->assertEquals('completed', $result['execution']->status);
    }

    public function test_runpod_api_error_results_in_failed_execution(): void
    {
        $this->storeApiKey();

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
        $this->storeApiKey();

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

    public function test_bearer_token_is_sent_in_request(): void
    {
        $this->storeApiKey('rpa_my_secret_key_xyz');

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
                && $request->header('Authorization')[0] === 'Bearer rpa_my_secret_key_xyz';
        });
    }
}
