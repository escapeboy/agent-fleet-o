<?php

namespace Tests\Unit\Domain\Integration\Drivers\Bitbucket;

use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use App\Domain\Integration\Drivers\Bitbucket\BitbucketBasicAuthDriver;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class BitbucketBasicAuthDriverTest extends TestCase
{
    use RefreshDatabase;

    private BitbucketBasicAuthDriver $driver;

    private Credential $credential;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new BitbucketBasicAuthDriver;

        $team = Team::factory()->create();
        $this->credential = Credential::factory()->create([
            'team_id' => $team->id,
            'credential_type' => CredentialType::BasicAuth,
            'status' => CredentialStatus::Active,
            'secret_data' => [
                'username' => 'nikola@lukanet.com',
                'password' => 'ATATT3xFfGF0_test_token',
                'workspace' => 'lukanet',
            ],
        ]);
    }

    public function test_it_uses_basic_auth_header_not_bearer(): void
    {
        Http::fake([
            'api.bitbucket.org/2.0/repositories/lukanet/collector/src/develop/README.md' => Http::response('hello'),
        ]);

        $this->driver->readFile($this->credential, 'collector', 'develop', 'README.md');

        Http::assertSent(function (Request $req): bool {
            $auth = $req->header('Authorization')[0] ?? '';
            $this->assertStringStartsWith('Basic ', $auth);
            $decoded = base64_decode(substr($auth, 6));
            $this->assertSame('nikola@lukanet.com:ATATT3xFfGF0_test_token', $decoded);

            return true;
        });
    }

    public function test_it_rejects_repo_outside_workspace_without_calling_api(): void
    {
        Http::fake();

        try {
            $this->driver->readFile($this->credential, 'acme/foo', 'main', 'README.md');
            $this->fail('Expected AccessDeniedHttpException');
        } catch (AccessDeniedHttpException $e) {
            $this->assertStringContainsString('outside allowed workspace', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_it_accepts_bare_slug_and_prepends_workspace(): void
    {
        Http::fake([
            'api.bitbucket.org/2.0/repositories/lukanet/collector/src/develop/path/file.php' => Http::response('content'),
        ]);

        $this->driver->readFile($this->credential, 'collector', 'develop', 'path/file.php');

        Http::assertSent(function (Request $req): bool {
            return str_contains($req->url(), '/repositories/lukanet/collector/src/develop/path/file.php');
        });
    }

    public function test_it_accepts_qualified_slug_when_workspace_matches(): void
    {
        Http::fake([
            'api.bitbucket.org/2.0/repositories/lukanet/collector/src/develop/x.txt' => Http::response('x'),
        ]);

        $this->driver->readFile($this->credential, 'lukanet/collector', 'develop', 'x.txt');

        Http::assertSent(fn (Request $req): bool => str_contains($req->url(), '/lukanet/collector/'));
    }

    public function test_it_throws_invalid_argument_when_workspace_missing(): void
    {
        Http::fake();

        $this->credential->secret_data = [
            'username' => 'u',
            'password' => 'p',
        ];
        $this->credential->save();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('workspace');

        $this->driver->readFile($this->credential, 'collector', 'develop', 'x.txt');

        Http::assertNothingSent();
    }

    public function test_it_throws_request_exception_on_401_with_token_not_leaked(): void
    {
        Http::fake([
            'api.bitbucket.org/*' => Http::response(['error' => ['message' => 'Bad token']], 401),
        ]);

        try {
            $this->driver->readFile($this->credential, 'collector', 'develop', 'x.txt');
            $this->fail('Expected RequestException');
        } catch (RequestException $e) {
            $this->assertSame(401, $e->response->status());
            $this->assertStringNotContainsString('ATATT3xFfGF0_test_token', $e->getMessage());
        }
    }

    public function test_it_throws_request_exception_on_404(): void
    {
        Http::fake([
            'api.bitbucket.org/*' => Http::response(['type' => 'error'], 404),
        ]);

        try {
            $this->driver->readFile($this->credential, 'collector', 'develop', 'missing.txt');
            $this->fail('Expected RequestException');
        } catch (RequestException $e) {
            $this->assertSame(404, $e->response->status());
        }
    }

    public function test_it_throws_request_exception_on_429_with_retry_after(): void
    {
        Http::fake([
            'api.bitbucket.org/*' => Http::response(['type' => 'error'], 429, ['Retry-After' => '30']),
        ]);

        try {
            $this->driver->readFile($this->credential, 'collector', 'develop', 'x.txt');
            $this->fail('Expected RequestException');
        } catch (RequestException $e) {
            $this->assertSame(429, $e->response->status());
            $this->assertSame('30', $e->response->header('Retry-After'));
        }
    }

    public function test_create_pull_request_posts_correct_payload_shape(): void
    {
        Http::fake([
            'api.bitbucket.org/2.0/repositories/lukanet/collector2/pullrequests' => Http::response([
                'id' => 42,
                'state' => 'OPEN',
                'links' => ['html' => ['href' => 'https://bitbucket.org/lukanet/collector2/pull-requests/42']],
            ], 201),
        ]);

        $result = $this->driver->createPullRequest(
            $this->credential,
            'collector2',
            'feat/fix',
            'develop',
            'Fix bug',
            'Body text',
        );

        $this->assertSame(42, $result['pr_number']);
        $this->assertSame('OPEN', $result['state']);
        $this->assertStringContainsString('pull-requests/42', $result['pr_url']);

        Http::assertSent(function (Request $req): bool {
            $body = $req->data();
            $this->assertSame('feat/fix', $body['source']['branch']['name']);
            $this->assertSame('develop', $body['destination']['branch']['name']);

            return true;
        });
    }
}
