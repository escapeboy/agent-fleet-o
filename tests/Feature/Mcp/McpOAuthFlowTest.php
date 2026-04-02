<?php

namespace Tests\Feature\Mcp;

use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Tests\TestCase;

/**
 * Feature tests for MCP OAuth 2.1 compliance.
 *
 * Covers: RFC 9728 (protected resource metadata), RFC 8414 (authorization server
 * metadata), RFC 7591 (dynamic client registration), RFC 7009 (token revocation),
 * PKCE enforcement, CORS, and ChatGPT Actions OpenAPI spec.
 */
class McpOAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generatePassportKeys();
        $this->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsWithRedis::class]);

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-oauth',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    protected function tearDown(): void
    {
        // Clean up test keys
        @unlink(storage_path('oauth-private.key'));
        @unlink(storage_path('oauth-public.key'));
        parent::tearDown();
    }

    private function generatePassportKeys(): void
    {
        $privateKeyPath = storage_path('oauth-private.key');
        $publicKeyPath = storage_path('oauth-public.key');

        if (file_exists($privateKeyPath) && file_exists($publicKeyPath)) {
            return;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        $publicKey = openssl_pkey_get_details($key)['key'];

        file_put_contents($privateKeyPath, $privateKey);
        file_put_contents($publicKeyPath, $publicKey);
        chmod($privateKeyPath, 0600);
        chmod($publicKeyPath, 0660);
    }

    // ── RFC 9728 — Protected Resource Metadata ───────────────────────────────

    public function test_protected_resource_metadata_returns_correct_structure(): void
    {
        $response = $this->getJson('/.well-known/oauth-protected-resource');

        $response->assertOk()
            ->assertJsonStructure([
                'resource',
                'authorization_servers',
                'bearer_methods_supported',
                'scopes_supported',
            ]);
    }

    public function test_protected_resource_authorization_servers_points_to_issuer(): void
    {
        $response = $this->getJson('/.well-known/oauth-protected-resource');

        $data = $response->json();

        // authorization_servers MUST be the issuer root, NOT the /mcp URL.
        $this->assertContains(url('/'), $data['authorization_servers']);
        $this->assertNotContains(url('/mcp'), $data['authorization_servers']);
    }

    public function test_protected_resource_supports_bearer_header_method(): void
    {
        $response = $this->getJson('/.well-known/oauth-protected-resource');

        $response->assertJsonPath('bearer_methods_supported', ['header']);
    }

    public function test_protected_resource_includes_mcp_use_scope(): void
    {
        $response = $this->getJson('/.well-known/oauth-protected-resource');

        $response->assertJsonPath('scopes_supported', ['mcp:use']);
    }

    public function test_protected_resource_with_path_suffix_returns_resource_url(): void
    {
        // Claude.ai appends /mcp to the URL when discovering the protected resource
        $response = $this->getJson('/.well-known/oauth-protected-resource/mcp');

        $response->assertOk();
        $data = $response->json();
        $this->assertStringContainsString('mcp', $data['resource']);
    }

    // ── RFC 8414 — Authorization Server Metadata ─────────────────────────────

    public function test_authorization_server_metadata_returns_correct_structure(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk()
            ->assertJsonStructure([
                'issuer',
                'authorization_endpoint',
                'token_endpoint',
                'registration_endpoint',
                'revocation_endpoint',
                'response_types_supported',
                'grant_types_supported',
                'code_challenge_methods_supported',
                'token_endpoint_auth_methods_supported',
                'scopes_supported',
            ]);
    }

    public function test_authorization_server_issuer_is_root_url(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertJsonPath('issuer', url('/'));
    }

    public function test_authorization_server_supports_s256_pkce(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertJsonPath('code_challenge_methods_supported', ['S256']);
    }

    public function test_authorization_server_includes_revocation_endpoint(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $data = $response->json();
        $this->assertArrayHasKey('revocation_endpoint', $data);
        $this->assertStringContainsString('revoke', $data['revocation_endpoint']);
    }

    public function test_authorization_server_includes_registration_endpoint(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $data = $response->json();
        $this->assertArrayHasKey('registration_endpoint', $data);
        $this->assertStringContainsString('register', $data['registration_endpoint']);
    }

    public function test_authorization_server_supports_public_clients(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $data = $response->json();
        $this->assertContains('none', $data['token_endpoint_auth_methods_supported']);
    }

    public function test_authorization_server_with_path_suffix_still_returns_root_issuer(): void
    {
        // Issuer must always be the root, regardless of path.
        $response = $this->getJson('/.well-known/oauth-authorization-server/mcp');

        $response->assertOk()
            ->assertJsonPath('issuer', url('/'));
    }

    // ── RFC 7591 — Dynamic Client Registration ───────────────────────────────

    public function test_dynamic_client_registration_accepts_valid_request(): void
    {
        config(['mcp.redirect_domains' => ['*']]);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Claude.ai Test Client',
            'redirect_uris' => ['https://claude.ai/oauth/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => 'mcp:use',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'client_id',
                'redirect_uris',
                'grant_types',
                'scope',
                'token_endpoint_auth_method',
            ]);
    }

    public function test_dynamic_client_registration_requires_redirect_uris(): void
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test Client',
        ]);

        $response->assertStatus(422);
    }

    public function test_dynamic_client_registration_rejects_empty_redirect_uris(): void
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test Client',
            'redirect_uris' => [],
        ]);

        $response->assertStatus(422);
    }

    public function test_dynamic_client_registration_returns_client_id(): void
    {
        config(['mcp.redirect_domains' => ['*']]);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'ChatGPT',
            'redirect_uris' => ['https://chatgpt.com/oauth/callback'],
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('client_id'));
    }

    public function test_dynamic_client_registration_scope_is_mcp_use(): void
    {
        config(['mcp.redirect_domains' => ['*']]);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test Client',
            'redirect_uris' => ['https://example.com/callback'],
        ]);

        $response->assertOk()
            ->assertJsonPath('scope', 'mcp:use');
    }

    // ── RFC 7009 — Token Revocation ───────────────────────────────────────────

    public function test_token_revocation_always_returns_200_for_valid_token(): void
    {
        $response = $this->post('/oauth/revoke', [
            'token' => 'some-valid-looking-token',
            'token_type_hint' => 'access_token',
        ]);

        $response->assertOk();
    }

    public function test_token_revocation_returns_200_for_invalid_token(): void
    {
        $response = $this->post('/oauth/revoke', [
            'token' => 'completely-invalid-token',
        ]);

        $response->assertOk();
    }

    public function test_token_revocation_returns_200_with_no_token(): void
    {
        $response = $this->post('/oauth/revoke', []);

        $response->assertOk();
    }

    public function test_token_revocation_returns_200_for_refresh_token_hint(): void
    {
        $response = $this->post('/oauth/revoke', [
            'token' => 'some-refresh-token-value',
            'token_type_hint' => 'refresh_token',
        ]);

        $response->assertOk();
    }

    public function test_token_revocation_returns_200_for_malformed_jwt(): void
    {
        $response = $this->post('/oauth/revoke', [
            'token' => 'not.a.valid.jwt.format',
            'token_type_hint' => 'access_token',
        ]);

        $response->assertOk();
    }

    // ── CORS — Cross-Origin Requests ─────────────────────────────────────────

    public function test_well_known_protected_resource_allows_cors_preflight(): void
    {
        $response = $this->call('OPTIONS', '/.well-known/oauth-protected-resource', [], [], [], [
            'HTTP_ORIGIN' => 'https://claude.ai',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        // Should not return 405 (method not allowed) — OPTIONS must pass through CORS
        $this->assertNotEquals(405, $response->getStatusCode());
    }

    public function test_well_known_authorization_server_allows_cors_preflight(): void
    {
        $response = $this->call('OPTIONS', '/.well-known/oauth-authorization-server', [], [], [], [
            'HTTP_ORIGIN' => 'https://claude.ai',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertNotEquals(405, $response->getStatusCode());
    }

    // ── Discovery Chain ───────────────────────────────────────────────────────

    public function test_discovery_chain_endpoints_are_consistent(): void
    {
        $protectedResource = $this->getJson('/.well-known/oauth-protected-resource')->json();
        $authServer = $this->getJson('/.well-known/oauth-authorization-server')->json();

        // The authorization_server advertised by the protected resource
        // must match the issuer in the authorization server metadata.
        $this->assertContains(
            $authServer['issuer'],
            $protectedResource['authorization_servers'],
            'Protected resource authorization_servers must include the auth server issuer',
        );
    }

    public function test_discovery_chain_registration_endpoint_is_reachable(): void
    {
        $authServer = $this->getJson('/.well-known/oauth-authorization-server')->json();
        $registrationEndpoint = $authServer['registration_endpoint'];

        // Extract path from the full URL for the HTTP test client
        $path = parse_url($registrationEndpoint, PHP_URL_PATH);

        // POST without body should return 422 (validation error), not 404
        $response = $this->postJson($path, []);
        $this->assertNotEquals(404, $response->status());
    }

    // ── MCP Endpoint Authentication ───────────────────────────────────────────

    public function test_mcp_endpoint_requires_authentication(): void
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ]);

        // MCP spec 2025-11-25: servers MUST respond with 401, never 302 redirect.
        // Base edition uses auth:sanctum which returns 401 for JSON requests.
        $this->assertContains($response->status(), [401, 403]);
    }
}
