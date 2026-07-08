<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Experiment\WarmBuildGuardrailsStatusTool;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class WarmBuildGuardrailsStatusToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'WB Team', 'slug' => 'wb-'.Str::random(6),
            'owner_id' => $this->user->id, 'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($this->user);
        app()->instance('mcp.team_id', $this->team->id);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    private function repo(Team $team, array $config = []): GitRepository
    {
        return GitRepository::create([
            'team_id' => $team->id, 'name' => 'r', 'url' => 'https://example.test/r.git',
            'default_branch' => 'main', 'config' => $config,
        ]);
    }

    public function test_reports_default_guardrails(): void
    {
        $repo = $this->repo($this->team);

        $out = $this->decode((new WarmBuildGuardrailsStatusTool)->handle(new Request([
            'git_repository_id' => $repo->id,
        ])));

        $this->assertSame($repo->id, $out['git_repository_id']);
        $this->assertSame(1, $out['best_of_n']['candidates'], 'default N is 1 (dark-ship)');
        $this->assertContains('database/migrations/**', $out['writable_roots']['denied_globs']);
        $this->assertSame([], $out['writable_roots']['allowed_roots']);
        $this->assertFalse($out['write_jail']['enabled']);
        $this->assertSame('disabled', $out['write_jail']['inert_reason']);
    }

    public function test_reflects_repo_overrides(): void
    {
        config(['experiments.warm_build.candidates_max' => 3]);
        $repo = $this->repo($this->team, [
            'warm_build_candidates' => 2,
            'warm_build_writable_roots' => ['allowed_roots' => ['app']],
            'warm_build_verify_command' => 'php artisan test',
        ]);

        $out = $this->decode((new WarmBuildGuardrailsStatusTool)->handle(new Request([
            'git_repository_id' => $repo->id,
        ])));

        $this->assertSame(2, $out['best_of_n']['candidates']);
        $this->assertTrue($out['best_of_n']['verify_command_set']);
        $this->assertSame(['app'], $out['writable_roots']['allowed_roots']);
    }

    public function test_cross_tenant_repo_is_not_visible(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other', 'slug' => 'other-'.Str::random(6),
            'owner_id' => $otherUser->id, 'settings' => [],
        ]);
        $foreignRepo = $this->repo($otherTeam);

        $this->expectException(ModelNotFoundException::class);
        (new WarmBuildGuardrailsStatusTool)->handle(new Request([
            'git_repository_id' => $foreignRepo->id,
        ]));
    }
}
