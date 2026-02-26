<?php

namespace Tests\Feature\Mcp;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Mcp\Tools\Compute\ComputeManageTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class ComputeManageToolTest extends TestCase
{
    use RefreshDatabase;

    private ComputeManageTool $tool;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = app(ComputeManageTool::class);

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-compute',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        app()->instance('mcp.team_id', $this->team->id);
    }

    private function request(array $args): Request
    {
        return new Request($args);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    // -------------------------------------------------------------------------
    // provider_list
    // -------------------------------------------------------------------------

    public function test_provider_list_returns_registered_providers(): void
    {
        $response = $this->tool->handle($this->request(['action' => 'provider_list']));

        $this->assertFalse($response->isError());
        $data = $this->decode($response);
        $this->assertArrayHasKey('providers', $data);
        $this->assertNotEmpty($data['providers']);

        $slugs = array_column($data['providers'], 'provider');
        $this->assertContains('runpod', $slugs);
        $this->assertContains('replicate', $slugs);
        $this->assertContains('fal', $slugs);
        $this->assertContains('vast', $slugs);
    }

    public function test_provider_list_shows_credential_configured_status(): void
    {
        TeamProviderCredential::create([
            'team_id' => $this->team->id,
            'provider' => 'vast',
            'credentials' => ['api_key' => 'test_key'],
            'is_active' => true,
        ]);

        $response = $this->tool->handle($this->request(['action' => 'provider_list']));

        $data = $this->decode($response);
        $providers = collect($data['providers'])->keyBy('provider');

        $this->assertTrue($providers['vast']['credential_configured']);
        $this->assertFalse($providers['replicate']['credential_configured']);
    }

    // -------------------------------------------------------------------------
    // credential_save
    // -------------------------------------------------------------------------

    public function test_credential_save_validates_and_persists(): void
    {
        Http::fake([
            'https://console.vast.ai/api/v0/endptjobs/*' => Http::response([], 200),
        ]);

        $response = $this->tool->handle($this->request([
            'action' => 'credential_save',
            'provider' => 'vast',
            'api_key' => 'valid_vast_key',
        ]));

        $this->assertFalse($response->isError());
        $data = $this->decode($response);
        $this->assertEquals('saved', $data['status']);
        $this->assertEquals('vast', $data['provider']);

        $this->assertDatabaseHas('team_provider_credentials', [
            'team_id' => $this->team->id,
            'provider' => 'vast',
        ]);
    }

    public function test_credential_save_fails_when_api_key_is_invalid(): void
    {
        Http::fake([
            'https://console.vast.ai/api/v0/endptjobs/*' => Http::response([], 401),
        ]);

        $response = $this->tool->handle($this->request([
            'action' => 'credential_save',
            'provider' => 'vast',
            'api_key' => 'bad_key',
        ]));

        $this->assertTrue($response->isError());
        $this->assertDatabaseMissing('team_provider_credentials', [
            'team_id' => $this->team->id,
            'provider' => 'vast',
        ]);
    }

    public function test_credential_save_requires_provider(): void
    {
        $response = $this->tool->handle($this->request([
            'action' => 'credential_save',
            'api_key' => 'some_key',
        ]));

        $this->assertTrue($response->isError());
    }

    public function test_credential_save_requires_api_key(): void
    {
        $response = $this->tool->handle($this->request([
            'action' => 'credential_save',
            'provider' => 'vast',
        ]));

        $this->assertTrue($response->isError());
    }

    public function test_credential_save_rejects_unknown_provider(): void
    {
        $response = $this->tool->handle($this->request([
            'action' => 'credential_save',
            'provider' => 'unknown_provider',
            'api_key' => 'some_key',
        ]));

        $this->assertTrue($response->isError());
    }

    // -------------------------------------------------------------------------
    // credential_check
    // -------------------------------------------------------------------------

    public function test_credential_check_returns_not_configured_when_missing(): void
    {
        $response = $this->tool->handle($this->request([
            'action' => 'credential_check',
            'provider' => 'replicate',
        ]));

        $this->assertFalse($response->isError());
        $data = $this->decode($response);
        $this->assertFalse($data['configured']);
        $this->assertFalse($data['valid']);
    }

    public function test_credential_check_validates_stored_credentials(): void
    {
        Http::fake([
            'https://console.vast.ai/api/v0/endptjobs/*' => Http::response([], 200),
        ]);

        TeamProviderCredential::create([
            'team_id' => $this->team->id,
            'provider' => 'vast',
            'credentials' => ['api_key' => 'vast_key_123'],
            'is_active' => true,
        ]);

        $response = $this->tool->handle($this->request([
            'action' => 'credential_check',
            'provider' => 'vast',
        ]));

        $this->assertFalse($response->isError());
        $data = $this->decode($response);
        $this->assertTrue($data['configured']);
        $this->assertTrue($data['valid']);
    }

    // -------------------------------------------------------------------------
    // credential_remove
    // -------------------------------------------------------------------------

    public function test_credential_remove_deletes_stored_credential(): void
    {
        TeamProviderCredential::create([
            'team_id' => $this->team->id,
            'provider' => 'fal',
            'credentials' => ['api_key' => 'fal_key'],
            'is_active' => true,
        ]);

        $response = $this->tool->handle($this->request([
            'action' => 'credential_remove',
            'provider' => 'fal',
        ]));

        $this->assertFalse($response->isError());
        $data = $this->decode($response);
        $this->assertEquals('removed', $data['status']);

        $this->assertDatabaseMissing('team_provider_credentials', [
            'team_id' => $this->team->id,
            'provider' => 'fal',
        ]);
    }

    public function test_credential_remove_returns_not_found_when_none_exists(): void
    {
        $response = $this->tool->handle($this->request([
            'action' => 'credential_remove',
            'provider' => 'replicate',
        ]));

        $this->assertFalse($response->isError());
        $data = $this->decode($response);
        $this->assertEquals('not_found', $data['status']);
    }

    // -------------------------------------------------------------------------
    // health_check
    // -------------------------------------------------------------------------

    public function test_health_check_returns_healthy_status(): void
    {
        Http::fake([
            'https://run.vast.ai/route/*' => Http::response(['url' => 'https://worker.vast.ai'], 200),
            'https://console.vast.ai/api/v0/endptjobs/*' => Http::response([], 200),
        ]);

        TeamProviderCredential::create([
            'team_id' => $this->team->id,
            'provider' => 'vast',
            'credentials' => ['api_key' => 'vast_key_123'],
            'is_active' => true,
        ]);

        $response = $this->tool->handle($this->request([
            'action' => 'health_check',
            'provider' => 'vast',
            'endpoint_id' => 'my-endpoint',
        ]));

        $this->assertFalse($response->isError());
        $data = $this->decode($response);
        $this->assertArrayHasKey('healthy', $data);
        $this->assertEquals('vast', $data['provider']);
        $this->assertEquals('my-endpoint', $data['endpoint_id']);
    }

    public function test_health_check_requires_provider_and_endpoint(): void
    {
        $response = $this->tool->handle($this->request([
            'action' => 'health_check',
            'provider' => 'vast',
        ]));

        $this->assertTrue($response->isError());
    }

    // -------------------------------------------------------------------------
    // run
    // -------------------------------------------------------------------------

    public function test_run_executes_job_and_returns_result(): void
    {
        Http::fake([
            'https://run.vast.ai/route/*' => Http::response(['url' => 'https://worker.vast.ai'], 200),
            'https://worker.vast.ai/*' => Http::response(['choices' => [['text' => 'hello']]], 200),
        ]);

        TeamProviderCredential::create([
            'team_id' => $this->team->id,
            'provider' => 'vast',
            'credentials' => ['api_key' => 'vast_key_123'],
            'is_active' => true,
        ]);

        $response = $this->tool->handle($this->request([
            'action' => 'run',
            'provider' => 'vast',
            'endpoint_id' => 'my-endpoint',
            'input' => ['prompt' => 'hello'],
            'use_sync' => true,
        ]));

        $this->assertFalse($response->isError());
        $data = $this->decode($response);
        $this->assertEquals('completed', $data['status']);
        $this->assertEquals('vast', $data['provider']);
        $this->assertArrayHasKey('output', $data);
    }

    public function test_run_requires_endpoint_id(): void
    {
        $response = $this->tool->handle($this->request([
            'action' => 'run',
            'provider' => 'vast',
        ]));

        $this->assertTrue($response->isError());
    }

    public function test_run_requires_team_context(): void
    {
        // bind a factory so the container returns null (instance(null) silently fails due to isset())
        app()->bind('mcp.team_id', fn () => null);

        $response = $this->tool->handle($this->request([
            'action' => 'run',
            'provider' => 'vast',
            'endpoint_id' => 'my-endpoint',
        ]));

        $this->assertTrue($response->isError());
    }

    // -------------------------------------------------------------------------
    // unknown action
    // -------------------------------------------------------------------------

    public function test_unknown_action_returns_error(): void
    {
        $response = $this->tool->handle($this->request([
            'action' => 'do_something_weird',
        ]));

        $this->assertTrue($response->isError());
    }
}
