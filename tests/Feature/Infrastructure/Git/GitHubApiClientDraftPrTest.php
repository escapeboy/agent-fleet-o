<?php

namespace Tests\Feature\Infrastructure\Git;

use App\Domain\Credential\Models\Credential;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\Git\Clients\GitHubApiClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GitHubApiClientDraftPrTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_pull_request_forwards_draft_flag(): void
    {
        $user = User::factory()->create();
        $team = Team::create(['name' => 'T', 'slug' => 't-'.Str::random(6), 'owner_id' => $user->id, 'settings' => []]);

        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'secret_data' => ['token' => 'ghp_test'],
        ]);

        $repo = GitRepository::create([
            'team_id' => $team->id,
            'name' => 'r',
            'url' => 'https://github.com/acme/widgets',
            'credential_id' => $credential->id,
        ]);

        Http::fake([
            'api.github.com/repos/acme/widgets/pulls' => Http::response([
                'number' => 42,
                'html_url' => 'https://github.com/acme/widgets/pull/42',
                'title' => 'Fix',
                'state' => 'open',
            ], 201),
        ]);

        $client = new GitHubApiClient($repo->load('credential'));
        $result = $client->createPullRequest('Fix', 'body', 'fleetq/fix-1', 'main', draft: true);

        $this->assertSame('42', $result['pr_number']);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.github.com/repos/acme/widgets/pulls'
                && $request['draft'] === true
                && $request['head'] === 'fleetq/fix-1';
        });
    }
}
