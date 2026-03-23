<?php

namespace Tests\Feature\Domain\Integration;

use App\Domain\Credential\Models\Credential;
use App\Domain\Integration\Drivers\LinkedIn\LinkedInIntegrationDriver;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LinkedInIntegrationDriverTest extends TestCase
{
    use RefreshDatabase;

    private LinkedInIntegrationDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new LinkedInIntegrationDriver;
    }

    public function test_key_returns_linkedin(): void
    {
        $this->assertSame('linkedin', $this->driver->key());
    }

    public function test_label_returns_linkedin(): void
    {
        $this->assertSame('LinkedIn', $this->driver->label());
    }

    public function test_auth_type_is_oauth2(): void
    {
        $this->assertSame(AuthType::OAuth2, $this->driver->authType());
    }

    public function test_poll_frequency_is_zero(): void
    {
        $this->assertSame(0, $this->driver->pollFrequency());
    }

    public function test_supports_webhooks_returns_false(): void
    {
        $this->assertFalse($this->driver->supportsWebhooks());
    }

    public function test_triggers_returns_empty_array(): void
    {
        $this->assertSame([], $this->driver->triggers());
    }

    public function test_actions_returns_expected_action_keys(): void
    {
        $actions = $this->driver->actions();
        $keys = array_map(fn ($a) => $a->key, $actions);

        $this->assertContains('get_profile', $keys);
        $this->assertContains('get_organizations', $keys);
        $this->assertContains('post_text', $keys);
        $this->assertContains('post_link', $keys);
        $this->assertContains('comment', $keys);
    }

    public function test_validate_credentials_returns_true_when_userinfo_succeeds(): void
    {
        Http::fake([
            'api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc123', 'name' => 'Test User'], 200),
        ]);

        $result = $this->driver->validateCredentials(['access_token' => 'valid-token']);

        $this->assertTrue($result);
    }

    public function test_validate_credentials_returns_false_when_userinfo_fails(): void
    {
        Http::fake([
            'api.linkedin.com/v2/userinfo' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $result = $this->driver->validateCredentials(['access_token' => 'bad-token']);

        $this->assertFalse($result);
    }

    public function test_validate_credentials_returns_false_when_token_missing(): void
    {
        $result = $this->driver->validateCredentials([]);

        $this->assertFalse($result);
    }

    public function test_ping_returns_ok_when_userinfo_succeeds(): void
    {
        Http::fake([
            'api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc123', 'name' => 'Test User'], 200),
        ]);

        $integration = $this->makeIntegration(['access_token' => 'valid-token', 'name' => 'Test User']);

        $result = $this->driver->ping($integration);

        $this->assertTrue($result->healthy);
        $this->assertNull($result->message);
    }

    public function test_ping_returns_fail_when_userinfo_fails(): void
    {
        Http::fake([
            'api.linkedin.com/v2/userinfo' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $integration = $this->makeIntegration(['access_token' => 'bad-token']);

        $result = $this->driver->ping($integration);

        $this->assertFalse($result->healthy);
    }

    public function test_execute_get_profile_returns_person_urn_and_name(): void
    {
        Http::fake([
            'api.linkedin.com/v2/userinfo' => Http::response([
                'sub' => 'abc123',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'picture' => 'https://example.com/photo.jpg',
            ], 200),
        ]);

        $integration = $this->makeIntegration(['access_token' => 'valid-token']);

        /** @var array<string, mixed> $profile */
        $profile = $this->driver->execute($integration, 'get_profile', []);

        $this->assertSame('urn:li:person:abc123', $profile['person_urn']);
        $this->assertSame('Test User', $profile['name']);
        $this->assertSame('test@example.com', $profile['email']);
    }

    public function test_execute_post_text_calls_ugc_posts_api(): void
    {
        Http::fake([
            'api.linkedin.com/v2/ugcPosts' => Http::response([], 201, ['X-RestLi-Id' => 'urn:li:ugcPost:999']),
        ]);

        $integration = $this->makeIntegration([
            'access_token' => 'valid-token',
            'person_urn' => 'urn:li:person:abc123',
        ]);

        $result = $this->driver->execute($integration, 'post_text', [
            'text' => 'Hello LinkedIn!',
        ]);

        $this->assertSame('urn:li:ugcPost:999', $result['id']);
        $this->assertSame(201, $result['status']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/ugcPosts')
                && $request->data()['specificContent']['com.linkedin.ugc.ShareContent']['shareCommentary']['text'] === 'Hello LinkedIn!'
                && $request->data()['author'] === 'urn:li:person:abc123';
        });
    }

    public function test_execute_post_text_uses_organization_author_when_specified(): void
    {
        Http::fake([
            'api.linkedin.com/v2/ugcPosts' => Http::response([], 201, ['X-RestLi-Id' => 'urn:li:ugcPost:888']),
        ]);

        $integration = $this->makeIntegration([
            'access_token' => 'valid-token',
            'person_urn' => 'urn:li:person:abc123',
        ]);

        $this->driver->execute($integration, 'post_text', [
            'text' => 'Company post',
            'author_type' => 'organization',
            'organization_id' => '12345678',
        ]);

        Http::assertSent(function ($request) {
            return $request->data()['author'] === 'urn:li:organization:12345678';
        });
    }

    public function test_execute_post_link_includes_article_media(): void
    {
        Http::fake([
            'api.linkedin.com/v2/ugcPosts' => Http::response([], 201, ['X-RestLi-Id' => 'urn:li:ugcPost:777']),
        ]);

        $integration = $this->makeIntegration([
            'access_token' => 'valid-token',
            'person_urn' => 'urn:li:person:abc123',
        ]);

        $this->driver->execute($integration, 'post_link', [
            'text' => 'Check this out!',
            'url' => 'https://example.com/article',
        ]);

        Http::assertSent(function ($request) {
            $content = $request->data()['specificContent']['com.linkedin.ugc.ShareContent'];

            return $content['shareMediaCategory'] === 'ARTICLE'
                && $content['media'][0]['originalUrl'] === 'https://example.com/article';
        });
    }

    public function test_execute_comment_sends_request_to_social_actions_api(): void
    {
        Http::fake([
            'api.linkedin.com/rest/socialActions/*' => Http::response(['id' => 'comment-123'], 201),
        ]);

        $integration = $this->makeIntegration([
            'access_token' => 'valid-token',
            'person_urn' => 'urn:li:person:abc123',
        ]);

        $result = $this->driver->execute($integration, 'comment', [
            'text' => 'Great post!',
            'post_urn' => 'urn:li:ugcPost:987654321',
        ]);

        $this->assertSame('comment-123', $result['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/rest/socialActions/')
                && str_contains($request->url(), '/comments')
                && $request->data()['message']['text'] === 'Great post!';
        });
    }

    public function test_execute_comment_extracts_urn_from_feed_update_url(): void
    {
        Http::fake([
            'api.linkedin.com/rest/socialActions/*' => Http::response(['id' => 'comment-456'], 201),
        ]);

        $integration = $this->makeIntegration([
            'access_token' => 'valid-token',
            'person_urn' => 'urn:li:person:abc123',
        ]);

        $this->driver->execute($integration, 'comment', [
            'text' => 'Nice one!',
            'post_url' => 'https://www.linkedin.com/feed/update/urn:li:ugcPost:987654321',
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'urn%3Ali%3AugcPost%3A987654321');
        });
    }

    public function test_execute_comment_requires_post_urn_or_url(): void
    {
        $this->expectException(HttpException::class);

        $integration = $this->makeIntegration([
            'access_token' => 'valid-token',
            'person_urn' => 'urn:li:person:abc123',
        ]);

        $this->driver->execute($integration, 'comment', [
            'text' => 'Missing URN and URL',
        ]);
    }

    public function test_execute_refreshes_token_when_expired(): void
    {
        Http::fake([
            'linkedin.com/oauth/v2/accessToken' => Http::response([
                'access_token' => 'new-token',
                'expires_in' => 5183999,
            ], 200),
            'api.linkedin.com/v2/ugcPosts' => Http::response([], 201, ['X-RestLi-Id' => 'urn:li:ugcPost:111']),
        ]);

        $integration = $this->makeIntegration([
            'access_token' => 'expired-token',
            'refresh_token' => 'valid-refresh-token',
            'token_expires_at' => now()->subHour()->toIso8601String(),
            'person_urn' => 'urn:li:person:abc123',
        ]);

        $this->driver->execute($integration, 'post_text', ['text' => 'Refreshed post']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/oauth/v2/accessToken');
        });
    }

    public function test_execute_unknown_action_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $integration = $this->makeIntegration(['access_token' => 'valid-token']);

        $this->driver->execute($integration, 'nonexistent_action', []);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $secretData
     */
    private function makeIntegration(array $secretData): Integration
    {
        $team = Team::factory()->create();

        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'secret_data' => $secretData,
        ]);

        return Integration::factory()->create([
            'team_id' => $team->id,
            'driver' => 'linkedin',
            'credential_id' => $credential->id,
        ]);
    }
}
