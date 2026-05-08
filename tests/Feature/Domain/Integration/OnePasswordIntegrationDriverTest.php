<?php

namespace Tests\Feature\Domain\Integration;

use App\Domain\Credential\Models\Credential;
use App\Domain\Integration\Drivers\OnePassword\OnePasswordIntegrationDriver;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\Secrets\OnePasswordResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class OnePasswordIntegrationDriverTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_TOKEN = 'ops_eyJzaWduSW5BZGRyZXNzIjoidGVzdC4xcGFzc3dvcmQuY29tIn0';

    private OnePasswordIntegrationDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = $this->app->make(OnePasswordIntegrationDriver::class);
    }

    public function test_key_returns_1password(): void
    {
        $this->assertSame('1password', $this->driver->key());
    }

    public function test_label_returns_1password(): void
    {
        $this->assertSame('1Password', $this->driver->label());
    }

    public function test_auth_type_is_api_key(): void
    {
        $this->assertSame(AuthType::ApiKey, $this->driver->authType());
    }

    public function test_validate_credentials_accepts_well_formed_ops_token(): void
    {
        $this->assertTrue($this->driver->validateCredentials([
            'service_account_token' => self::VALID_TOKEN,
        ]));
    }

    public function test_validate_credentials_rejects_short_token(): void
    {
        $this->assertFalse($this->driver->validateCredentials([
            'service_account_token' => 'ops_short',
        ]));
    }

    public function test_validate_credentials_rejects_token_without_ops_prefix(): void
    {
        $this->assertFalse($this->driver->validateCredentials([
            'service_account_token' => str_repeat('a', 64),
        ]));
    }

    public function test_actions_returns_expected_keys(): void
    {
        $keys = array_map(fn ($a) => $a->key, $this->driver->actions());

        $this->assertContains('list_vaults', $keys);
        $this->assertContains('search_items', $keys);
        $this->assertContains('get_item', $keys);
        $this->assertContains('resolve_secret', $keys);
    }

    public function test_ping_reports_healthy_with_vault_count_when_op_succeeds(): void
    {
        Process::fake(fn () => new FakeProcessResult(
            output: json_encode([
                ['id' => 'vault1', 'name' => 'Engineering', 'description' => 'Engineering secrets'],
                ['id' => 'vault2', 'name' => 'Production'],
            ]),
            exitCode: 0,
        ));

        $integration = $this->makeIntegration(['service_account_token' => self::VALID_TOKEN]);

        $result = $this->driver->ping($integration);

        $this->assertTrue($result->healthy);
        $this->assertStringContainsString('2 vault(s)', $result->message);
    }

    public function test_ping_reports_unhealthy_when_op_fails(): void
    {
        Process::fake(fn () => new FakeProcessResult(
            output: '',
            errorOutput: 'authentication required: invalid token',
            exitCode: 1,
        ));

        $integration = $this->makeIntegration(['service_account_token' => self::VALID_TOKEN]);

        $result = $this->driver->ping($integration);

        $this->assertFalse($result->healthy);
        $this->assertStringContainsString('authentication required', $result->message);
    }

    public function test_list_vaults_passes_token_via_env_not_argv(): void
    {
        Process::fake(fn () => new FakeProcessResult(
            output: json_encode([['id' => 'v1', 'name' => 'Vault A']]),
            exitCode: 0,
        ));

        $integration = $this->makeIntegration(['service_account_token' => self::VALID_TOKEN]);

        $vaults = $this->driver->execute($integration, 'list_vaults', []);

        $this->assertCount(1, $vaults);
        $this->assertSame('Vault A', $vaults[0]['name']);
        Process::assertRan(function ($process) {
            // Token must be in env, never on argv (would leak via `ps`).
            return $process->command === ['op', 'vault', 'list', '--format=json']
                && ($process->environment['OP_SERVICE_ACCOUNT_TOKEN'] ?? null) === self::VALID_TOKEN
                && ! in_array(self::VALID_TOKEN, $process->command, true);
        });
    }

    public function test_search_items_filters_client_side_by_title_substring(): void
    {
        Process::fake(fn () => new FakeProcessResult(
            output: json_encode([
                ['id' => 'i1', 'title' => 'Stripe API Key', 'category' => 'API_CREDENTIAL', 'vault' => ['id' => 'v1', 'name' => 'Eng']],
                ['id' => 'i2', 'title' => 'Twilio Auth', 'category' => 'API_CREDENTIAL', 'vault' => ['id' => 'v1', 'name' => 'Eng']],
                ['id' => 'i3', 'title' => 'GitHub Token', 'category' => 'API_CREDENTIAL', 'vault' => ['id' => 'v1', 'name' => 'Eng']],
            ]),
            exitCode: 0,
        ));

        $integration = $this->makeIntegration(['service_account_token' => self::VALID_TOKEN]);

        $items = $this->driver->execute($integration, 'search_items', ['query' => 'stripe']);

        $this->assertCount(1, $items);
        $this->assertSame('Stripe API Key', $items[0]['title']);
    }

    public function test_resolve_secret_returns_only_masked_preview_never_raw_value(): void
    {
        Process::fake(fn () => new FakeProcessResult(output: 'sk_live_supersecret_value', exitCode: 0));

        $integration = $this->makeIntegration(['service_account_token' => self::VALID_TOKEN]);

        $result = $this->driver->execute($integration, 'resolve_secret', [
            'reference' => 'op://Engineering/Stripe/credential',
        ]);

        $this->assertTrue($result['resolved']);
        $this->assertStringNotContainsString('supersecret', $result['value_preview']);
        $this->assertStringEndsWith('alue', $result['value_preview']);
        $this->assertSame(25, $result['value_length']);
    }

    public function test_unknown_action_throws(): void
    {
        $integration = $this->makeIntegration(['service_account_token' => self::VALID_TOKEN]);

        $this->expectException(\InvalidArgumentException::class);
        $this->driver->execute($integration, 'totally_made_up', []);
    }

    /**
     * Confirm the OnePasswordResolver is wired through DI — driver should
     * never instantiate it directly (would defeat Process::fake in tests).
     */
    public function test_resolver_is_resolved_from_container(): void
    {
        $resolver = $this->app->make(OnePasswordResolver::class);
        $this->assertInstanceOf(OnePasswordResolver::class, $resolver);
    }

    private function makeIntegration(array $secretData): Integration
    {
        $team = Team::factory()->create();

        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'secret_data' => $secretData,
        ]);

        return Integration::factory()->create([
            'team_id' => $team->id,
            'driver' => '1password',
            'credential_id' => $credential->id,
        ]);
    }
}
