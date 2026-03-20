<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Credential\Models\Credential;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Actions\ExecuteSupabaseEdgeFunctionSkillAction;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupabaseEdgeFunctionSkillTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private ExecuteSupabaseEdgeFunctionSkillAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);

        $this->action = app(ExecuteSupabaseEdgeFunctionSkillAction::class);
    }

    private function makeSkill(array $configuration = []): Skill
    {
        return Skill::factory()->for($this->team)->create([
            'type' => SkillType::SupabaseEdgeFunction,
            'configuration' => $configuration,
        ]);
    }

    private function makeCredential(string $serviceRoleKey): Credential
    {
        return Credential::factory()->for($this->team)->create([
            'secret_data' => ['key' => $serviceRoleKey],
        ]);
    }

    public function test_successfully_invokes_edge_function_and_records_execution(): void
    {
        $credential = $this->makeCredential('test-service-role-key');

        Http::fake([
            'xyzabcdef.supabase.co/functions/v1/my-function' => Http::response(
                ['result' => 'processed', 'count' => 42],
                200,
            ),
        ]);

        $skill = $this->makeSkill([
            'project_url' => 'https://xyzabcdef.supabase.co',
            'function_name' => 'my-function',
            'credential_id' => $credential->id,
        ]);

        $result = $this->action->execute(
            $skill,
            ['input_data' => 'test'],
            $this->team->id,
            'user-uuid',
        );

        $this->assertArrayHasKey('execution', $result);
        $this->assertArrayHasKey('output', $result);
        $this->assertSame('completed', $result['execution']->status);
        $this->assertSame('processed', $result['output']['result']);
        $this->assertSame(0, $result['execution']->cost_credits);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'xyzabcdef.supabase.co/functions/v1/my-function')
                && $request->hasHeader('Authorization', 'Bearer test-service-role-key')
                && str_contains($request->body(), 'input_data');
        });
    }

    public function test_fails_when_project_url_missing(): void
    {
        $credential = $this->makeCredential('some-key');
        $skill = $this->makeSkill([
            'function_name' => 'my-function',
            'credential_id' => $credential->id,
        ]);

        $result = $this->action->execute($skill, [], $this->team->id, 'user-uuid');

        $this->assertSame('failed', $result['execution']->status);
        $this->assertNull($result['output']);
        $this->assertStringContainsString('project_url', $result['execution']->error_message);
    }

    public function test_fails_when_credential_id_missing(): void
    {
        $skill = $this->makeSkill([
            'project_url' => 'https://xyzabcdef.supabase.co',
            'function_name' => 'my-function',
        ]);

        $result = $this->action->execute($skill, [], $this->team->id, 'user-uuid');

        $this->assertSame('failed', $result['execution']->status);
        $this->assertStringContainsString('credential_id', $result['execution']->error_message);
    }

    public function test_fails_when_edge_function_returns_4xx(): void
    {
        $credential = $this->makeCredential('key');

        Http::fake([
            'xyzabcdef.supabase.co/functions/v1/bad-fn' => Http::response(
                ['error' => 'Function not found'],
                404,
            ),
        ]);

        $skill = $this->makeSkill([
            'project_url' => 'https://xyzabcdef.supabase.co',
            'function_name' => 'bad-fn',
            'credential_id' => $credential->id,
        ]);

        $result = $this->action->execute($skill, [], $this->team->id, 'user-uuid');

        $this->assertSame('failed', $result['execution']->status);
        $this->assertStringContainsString('404', $result['execution']->error_message);
    }

    public function test_skill_type_enum_has_supabase_edge_function(): void
    {
        $this->assertSame('supabase_edge_function', SkillType::SupabaseEdgeFunction->value);
        $this->assertSame('Supabase Edge Function', SkillType::SupabaseEdgeFunction->label());
    }
}
