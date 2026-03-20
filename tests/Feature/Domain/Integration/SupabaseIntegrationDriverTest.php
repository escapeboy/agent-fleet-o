<?php

namespace Tests\Feature\Domain\Integration;

use App\Domain\Integration\Drivers\Supabase\SupabaseIntegrationDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupabaseIntegrationDriverTest extends TestCase
{
    use RefreshDatabase;

    private SupabaseIntegrationDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new SupabaseIntegrationDriver;
    }

    public function test_key_returns_supabase(): void
    {
        $this->assertSame('supabase', $this->driver->key());
    }

    public function test_auth_type_is_api_key(): void
    {
        $this->assertSame('api_key', $this->driver->authType()->value);
    }

    public function test_validate_credentials_succeeds_when_supabase_rest_returns_200(): void
    {
        Http::fake([
            'xyzabcdef.supabase.co/rest/v1/' => Http::response([], 200),
        ]);

        $result = $this->driver->validateCredentials([
            'project_url' => 'https://xyzabcdef.supabase.co',
            'service_role_key' => 'test-service-role-key',
        ]);

        $this->assertTrue($result);
    }

    public function test_validate_credentials_fails_when_supabase_rest_returns_error(): void
    {
        Http::fake([
            'xyzabcdef.supabase.co/rest/v1/' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $result = $this->driver->validateCredentials([
            'project_url' => 'https://xyzabcdef.supabase.co',
            'service_role_key' => 'bad-key',
        ]);

        $this->assertFalse($result);
    }

    public function test_validate_credentials_fails_when_project_url_missing(): void
    {
        $result = $this->driver->validateCredentials([
            'service_role_key' => 'some-key',
        ]);

        $this->assertFalse($result);
    }

    public function test_verify_webhook_signature_passes_with_correct_secret(): void
    {
        $result = $this->driver->verifyWebhookSignature(
            '{}',
            ['x-webhook-secret' => 'my-secret-123'],
            'my-secret-123',
        );

        $this->assertTrue($result);
    }

    public function test_verify_webhook_signature_fails_with_wrong_secret(): void
    {
        $result = $this->driver->verifyWebhookSignature(
            '{}',
            ['x-webhook-secret' => 'wrong-secret'],
            'my-secret-123',
        );

        $this->assertFalse($result);
    }

    public function test_verify_webhook_signature_fails_when_header_missing(): void
    {
        $result = $this->driver->verifyWebhookSignature('{}', [], 'my-secret-123');

        $this->assertFalse($result);
    }

    public function test_parse_webhook_payload_maps_cdc_insert_event(): void
    {
        $payload = [
            'type' => 'INSERT',
            'table' => 'users',
            'schema' => 'public',
            'record' => ['id' => 1, 'name' => 'Alice'],
            'old_record' => null,
        ];

        $signals = $this->driver->parseWebhookPayload($payload, []);

        $this->assertNotEmpty($signals);
        $signal = $signals[0];
        $this->assertSame('supabase_cdc', $signal['source_type']);
        $this->assertSame('INSERT', $signal['payload']['type']);
        $this->assertSame('users', $signal['payload']['table']);
        $this->assertContains('cdc', $signal['tags']);
        $this->assertContains('insert', $signal['tags']);
    }

    public function test_triggers_returns_table_change_trigger(): void
    {
        $triggers = $this->driver->triggers();

        $this->assertNotEmpty($triggers);
        $keys = array_map(fn ($t) => $t->key, $triggers);
        $this->assertContains('table_change', $keys);
    }

    public function test_actions_returns_expected_actions(): void
    {
        $actions = $this->driver->actions();

        $keys = array_map(fn ($a) => $a->key, $actions);
        $this->assertContains('query_table', $keys);
        $this->assertContains('execute_sql', $keys);
        $this->assertContains('invoke_edge_function', $keys);
        $this->assertContains('upload_storage_object', $keys);
    }

    public function test_supports_webhooks(): void
    {
        $this->assertTrue($this->driver->supportsWebhooks());
    }

    public function test_poll_frequency_is_zero(): void
    {
        $this->assertSame(0, $this->driver->pollFrequency());
    }
}
