<?php

namespace Tests\Feature\Mcp\Bitbucket;

use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Bitbucket\BitbucketPrCreateTool;
use App\Mcp\Tools\Bitbucket\BitbucketPrManageTool;
use App\Mcp\Tools\Bitbucket\BitbucketRepoReadFileTool;
use App\Mcp\Tools\Bitbucket\BitbucketRepoSearchTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class BitbucketToolsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    private Credential $credential;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->actingAs($this->user);

        app()->instance('mcp.team_id', $this->team->id);

        $this->credential = Credential::factory()->create([
            'team_id' => $this->team->id,
            'credential_type' => CredentialType::BasicAuth,
            'status' => CredentialStatus::Active,
            'secret_data' => [
                'username' => 'nikola@lukanet.com',
                'password' => 'token',
                'workspace' => 'lukanet',
            ],
        ]);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    // -----------------------------------------------------------------
    // bitbucket_repo_read_file
    // -----------------------------------------------------------------

    public function test_read_file_returns_content_on_happy_path(): void
    {
        Http::fake([
            'api.bitbucket.org/2.0/repositories/lukanet/collector/src/develop/Order.php' => Http::response('<?php class Order {}', 200),
        ]);

        $tool = new BitbucketRepoReadFileTool;
        $response = $tool->handle(new Request([
            'credential_id' => $this->credential->id,
            'repo_slug' => 'collector',
            'branch' => 'develop',
            'path' => 'Order.php',
        ]));

        $payload = $this->decode($response);
        $this->assertSame('Order.php', $payload['path']);
        $this->assertSame('develop', $payload['branch']);
        $this->assertSame('<?php class Order {}', $payload['content']);
        $this->assertSame(20, $payload['length']);
    }

    public function test_read_file_returns_permission_denied_on_workspace_mismatch(): void
    {
        $tool = new BitbucketRepoReadFileTool;
        $response = $tool->handle(new Request([
            'credential_id' => $this->credential->id,
            'repo_slug' => 'acme/foo',
            'branch' => 'main',
            'path' => 'README.md',
        ]));

        $payload = $this->decode($response);
        $this->assertSame('PERMISSION_DENIED', $payload['error']['code']);
    }

    public function test_read_file_returns_permission_denied_on_401(): void
    {
        Http::fake([
            'api.bitbucket.org/*' => Http::response(['error' => ['message' => 'Bad token']], 401),
        ]);

        $tool = new BitbucketRepoReadFileTool;
        $response = $tool->handle(new Request([
            'credential_id' => $this->credential->id,
            'repo_slug' => 'collector',
            'branch' => 'develop',
            'path' => 'x.txt',
        ]));

        $payload = $this->decode($response);
        $this->assertSame('PERMISSION_DENIED', $payload['error']['code']);
        $this->assertStringNotContainsString('token', strtolower((string) ($payload['error']['message'] ?? '')));
    }

    public function test_read_file_returns_failed_precondition_when_credential_not_basic_auth(): void
    {
        $apiTokenCred = Credential::factory()->create([
            'team_id' => $this->team->id,
            'credential_type' => CredentialType::ApiToken,
            'secret_data' => ['token' => 'foo'],
        ]);

        $tool = new BitbucketRepoReadFileTool;
        $response = $tool->handle(new Request([
            'credential_id' => $apiTokenCred->id,
            'repo_slug' => 'collector',
            'branch' => 'develop',
            'path' => 'x.txt',
        ]));

        $payload = $this->decode($response);
        $this->assertSame('FAILED_PRECONDITION', $payload['error']['code']);
    }

    public function test_read_file_returns_not_found_for_unknown_credential(): void
    {
        $tool = new BitbucketRepoReadFileTool;
        $response = $tool->handle(new Request([
            'credential_id' => '00000000-0000-7000-8000-000000000000',
            'repo_slug' => 'collector',
            'branch' => 'develop',
            'path' => 'x.txt',
        ]));

        $payload = $this->decode($response);
        $this->assertSame('NOT_FOUND', $payload['error']['code']);
    }

    public function test_read_file_does_not_leak_other_team_credential(): void
    {
        $otherTeam = Team::factory()->create();
        $otherCred = Credential::factory()->create([
            'team_id' => $otherTeam->id,
            'credential_type' => CredentialType::BasicAuth,
            'secret_data' => ['username' => 'x', 'password' => 'y', 'workspace' => 'lukanet'],
        ]);

        $tool = new BitbucketRepoReadFileTool;
        $response = $tool->handle(new Request([
            'credential_id' => $otherCred->id,
            'repo_slug' => 'collector',
            'branch' => 'develop',
            'path' => 'x.txt',
        ]));

        $payload = $this->decode($response);
        $this->assertSame('NOT_FOUND', $payload['error']['code']);
    }

    // -----------------------------------------------------------------
    // bitbucket_repo_search
    // -----------------------------------------------------------------

    public function test_search_returns_matches_on_happy_path(): void
    {
        Http::fake([
            'api.bitbucket.org/2.0/workspaces/lukanet/search/code*' => Http::response([
                'values' => [
                    [
                        'file' => ['path' => 'src/Order.php'],
                        'content_matches' => [
                            [
                                'lines' => [
                                    [
                                        'line' => 42,
                                        'segments' => [
                                            ['type' => 'TEXT', 'text' => 'function '],
                                            ['type' => 'MATCH', 'text' => 'computePrice'],
                                            ['type' => 'TEXT', 'text' => '($order)'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $tool = new BitbucketRepoSearchTool;
        $response = $tool->handle(new Request([
            'credential_id' => $this->credential->id,
            'repo_slug' => 'collector',
            'branch' => 'master',
            'pattern' => 'computePrice',
        ]));

        $payload = $this->decode($response);
        $this->assertSame(1, $payload['count']);
        $this->assertSame('src/Order.php', $payload['matches'][0]['path']);
        $this->assertSame(42, $payload['matches'][0]['line_number']);
        $this->assertSame('function computePrice($order)', $payload['matches'][0]['line_content']);
    }

    public function test_search_returns_permission_denied_on_workspace_mismatch(): void
    {
        $tool = new BitbucketRepoSearchTool;
        $response = $tool->handle(new Request([
            'credential_id' => $this->credential->id,
            'repo_slug' => 'acme/foo',
            'branch' => 'main',
            'pattern' => 'foo',
        ]));

        $this->assertSame('PERMISSION_DENIED', $this->decode($response)['error']['code']);
    }

    public function test_search_forwards_path_filter_qualifier(): void
    {
        Http::fake([
            'api.bitbucket.org/2.0/workspaces/lukanet/search/code*' => Http::response(['values' => []], 200),
        ]);

        $tool = new BitbucketRepoSearchTool;
        $tool->handle(new Request([
            'credential_id' => $this->credential->id,
            'repo_slug' => 'collector',
            'branch' => 'master',
            'pattern' => 'compute',
            'path_filter' => 'src/**/*.php',
        ]));

        Http::assertSent(function ($req): bool {
            return str_contains($req->url(), 'path%3Asrc%2F%2A%2A%2F%2A.php')
                || str_contains(urldecode($req->url()), 'path:src/**/*.php');
        });
    }

    // -----------------------------------------------------------------
    // bitbucket_pr_create
    // -----------------------------------------------------------------

    public function test_pr_create_returns_pr_metadata_on_happy_path(): void
    {
        Http::fake([
            'api.bitbucket.org/2.0/repositories/lukanet/collector2/pullrequests' => Http::response([
                'id' => 99,
                'state' => 'OPEN',
                'links' => ['html' => ['href' => 'https://bitbucket.org/lukanet/collector2/pull-requests/99']],
            ], 201),
        ]);

        $tool = new BitbucketPrCreateTool;
        $response = $tool->handle(new Request([
            'credential_id' => $this->credential->id,
            'repo_slug' => 'collector2',
            'source_branch' => 'feat/fix',
            'destination_branch' => 'develop',
            'title' => 'Fix bug',
            'description' => 'Body',
        ]));

        $payload = $this->decode($response);
        $this->assertSame(99, $payload['pr_number']);
        $this->assertSame('OPEN', $payload['state']);
        $this->assertStringContainsString('pull-requests/99', $payload['pr_url']);
    }

    public function test_pr_create_returns_permission_denied_on_workspace_mismatch(): void
    {
        $tool = new BitbucketPrCreateTool;
        $response = $tool->handle(new Request([
            'credential_id' => $this->credential->id,
            'repo_slug' => 'acme/repo',
            'source_branch' => 'a',
            'destination_branch' => 'b',
            'title' => 't',
            'description' => 'd',
        ]));

        $this->assertSame('PERMISSION_DENIED', $this->decode($response)['error']['code']);
    }

    public function test_pr_create_returns_permission_denied_on_403(): void
    {
        Http::fake([
            'api.bitbucket.org/*' => Http::response(['error' => ['message' => 'Forbidden']], 403),
        ]);

        $tool = new BitbucketPrCreateTool;
        $response = $tool->handle(new Request([
            'credential_id' => $this->credential->id,
            'repo_slug' => 'collector2',
            'source_branch' => 'a',
            'destination_branch' => 'b',
            'title' => 't',
            'description' => 'd',
        ]));

        $this->assertSame('PERMISSION_DENIED', $this->decode($response)['error']['code']);
    }

    // -----------------------------------------------------------------
    // bitbucket_pr_manage
    // -----------------------------------------------------------------

    public function test_pr_manage_comment_posts_to_comments_endpoint(): void
    {
        Http::fake([
            'api.bitbucket.org/2.0/repositories/lukanet/collector2/pullrequests/12/comments' => Http::response([
                'id' => 5,
                'content' => ['raw' => 'thanks'],
            ], 201),
        ]);

        $tool = new BitbucketPrManageTool;
        $response = $tool->handle(new Request([
            'credential_id' => $this->credential->id,
            'repo_slug' => 'collector2',
            'pr_id' => 12,
            'action' => 'comment',
            'body' => 'thanks',
        ]));

        $payload = $this->decode($response);
        $this->assertSame('comment', $payload['action']);
        $this->assertSame(12, $payload['pr_id']);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/pullrequests/12/comments'));
    }

    public function test_pr_manage_close_posts_to_decline_endpoint(): void
    {
        Http::fake([
            'api.bitbucket.org/2.0/repositories/lukanet/collector2/pullrequests/12/decline' => Http::response([
                'state' => 'DECLINED',
            ], 200),
        ]);

        $tool = new BitbucketPrManageTool;
        $response = $tool->handle(new Request([
            'credential_id' => $this->credential->id,
            'repo_slug' => 'collector2',
            'pr_id' => 12,
            'action' => 'close',
        ]));

        $payload = $this->decode($response);
        $this->assertSame('close', $payload['action']);
        $this->assertSame('DECLINED', $payload['state']);
    }

    public function test_pr_manage_merge_posts_strategy_when_provided(): void
    {
        Http::fake([
            'api.bitbucket.org/2.0/repositories/lukanet/collector2/pullrequests/12/merge' => Http::response([
                'state' => 'MERGED',
            ], 200),
        ]);

        $tool = new BitbucketPrManageTool;
        $response = $tool->handle(new Request([
            'credential_id' => $this->credential->id,
            'repo_slug' => 'collector2',
            'pr_id' => 12,
            'action' => 'merge',
            'merge_strategy' => 'squash',
        ]));

        $payload = $this->decode($response);
        $this->assertSame('merge', $payload['action']);
        $this->assertSame('MERGED', $payload['state']);

        Http::assertSent(function ($req): bool {
            return str_contains($req->url(), '/pullrequests/12/merge')
                && ($req->data()['merge_strategy'] ?? null) === 'squash';
        });
    }

    public function test_pr_manage_returns_invalid_argument_for_unknown_action(): void
    {
        $tool = new BitbucketPrManageTool;
        $response = $tool->handle(new Request([
            'credential_id' => $this->credential->id,
            'repo_slug' => 'collector2',
            'pr_id' => 12,
            'action' => 'reopen',
        ]));

        $this->assertSame('INVALID_ARGUMENT', $this->decode($response)['error']['code']);
    }

    public function test_pr_manage_returns_invalid_argument_when_comment_body_missing(): void
    {
        $tool = new BitbucketPrManageTool;
        $response = $tool->handle(new Request([
            'credential_id' => $this->credential->id,
            'repo_slug' => 'collector2',
            'pr_id' => 12,
            'action' => 'comment',
        ]));

        $this->assertSame('INVALID_ARGUMENT', $this->decode($response)['error']['code']);
    }
}
