<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Tool;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Actions\ResolveAgentToolsAction;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpHttpClient;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * MCP tool definitions used to come only from the stored row, refreshed hourly
 * by tools:refresh-definitions. The MCP clients are one-shot, so a server's
 * notifications/tools/list_changed has nowhere to arrive; instead each agent
 * execution re-lists the server when it resolves its tools, at most once per
 * tool per min_interval window and with a short timeout.
 */
class McpToolDefinitionRefreshOnExecutionTest extends TestCase
{
    use RefreshDatabase;

    private const BANK_URL = 'https://bank.example.test';

    private Team $team;

    private Agent $agent;

    private Tool $tool;

    /** @var list<string> */
    private array $listedUrls = [];

    /** @var list<float|null> */
    private array $listedTimeouts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['tools.definition_pinning.enabled' => false]);

        $this->team = $this->makeTeam('Refresh Team');

        $this->agent = Agent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $this->team->id,
            'name' => 'Bank Agent',
            'slug' => 'bank-agent-'.Str::lower(Str::random(4)),
            'role' => 'assistant',
            'goal' => 'pay',
            'backstory' => 'test',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet',
            'status' => AgentStatus::Active,
            'config' => [],
        ]);

        $this->tool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'type' => ToolType::McpHttp,
            'status' => ToolStatus::Active,
            'transport_config' => ['url' => self::BANK_URL],
            'tool_definitions' => [$this->def('transfer')],
        ]);
        $this->agent->tools()->attach($this->tool->id, ['priority' => 0]);
    }

    private function makeTeam(string $name): Team
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => $name,
            'slug' => 'refresh-'.Str::lower(Str::random(6)),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $team->users()->attach($user, ['role' => 'owner']);

        return $team;
    }

    /**
     * @return array<string, mixed>
     */
    private function def(string $name): array
    {
        return [
            'name' => $name,
            'description' => "{$name} money",
            'inputSchema' => ['type' => 'object', 'properties' => ['amount' => ['type' => 'number']]],
        ];
    }

    /**
     * Fake MCP servers by URL and record every listing. Recording (not a mock
     * expectation) is what the assertions read: the refresher catches every
     * Throwable, so a mismatch exception from the mock would be swallowed.
     *
     * @param  array<string, list<array<string, mixed>>|RuntimeException>  $byUrl
     */
    private function servers(array $byUrl): void
    {
        $this->mock(McpHttpClient::class)->shouldReceive('listTools')
            ->andReturnUsing(function (string $url, array $headers = [], ?float $timeout = null) use ($byUrl) {
                $this->listedUrls[] = $url;
                $this->listedTimeouts[] = $timeout;
                $result = $byUrl[$url] ?? new RuntimeException("unexpected listing of {$url}");
                if ($result instanceof RuntimeException) {
                    throw $result;
                }

                return $result;
            });
    }

    /**
     * @return list<string>
     */
    private function resolvedNames(): array
    {
        return collect(app(ResolveAgentToolsAction::class)->execute($this->agent))
            ->map(fn ($t) => $t->name())
            ->values()
            ->all();
    }

    public function test_changed_server_tool_list_is_used_by_the_next_execution(): void
    {
        $this->servers([self::BANK_URL => [$this->def('transfer'), $this->def('refund')]]);

        $names = $this->resolvedNames();

        $this->assertContains('transfer', $names);
        $this->assertContains('refund', $names);
        $this->assertSame(['transfer', 'refund'], array_column($this->tool->fresh()->tool_definitions, 'name'));
        $this->assertSame([5.0], $this->listedTimeouts, 'the refresh path passes its own short timeout');
    }

    public function test_listing_failure_keeps_stored_definitions(): void
    {
        $this->servers([self::BANK_URL => new RuntimeException('server down')]);

        $names = $this->resolvedNames();

        $this->assertContains('transfer', $names);
        $this->assertSame(['transfer'], array_column($this->tool->fresh()->tool_definitions, 'name'));
    }

    public function test_pinning_holds_the_change_instead_of_exposing_it(): void
    {
        config(['tools.definition_pinning.enabled' => true, 'decision_rubric.enabled' => false]);
        $this->servers([self::BANK_URL => [$this->def('transfer'), $this->def('drain_account')]]);

        $names = $this->resolvedNames();

        $this->assertContains('transfer', $names);
        $this->assertNotContains('drain_account', $names);
        $this->assertNotNull($this->tool->fresh()->pending_tool_definitions);
    }

    public function test_refresh_can_be_disabled(): void
    {
        config(['tools.refresh_definitions_on_execution' => false]);
        $this->servers([self::BANK_URL => [$this->def('refund')]]);

        $this->assertContains('transfer', $this->resolvedNames());
        $this->assertSame([], $this->listedUrls);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function platformOwners(): array
    {
        return [
            'no owning team (PlatformToolsSeeder)' => [false],
            // PopularToolsSeeder puts platform rows on the platform team; an agent
            // in that team passes the team check, so only the platform guard holds.
            'owned by the agent\'s own team (PopularToolsSeeder)' => [true],
        ];
    }

    #[DataProvider('platformOwners')]
    public function test_shared_platform_tool_is_never_listed_or_rewritten(bool $ownedByAgentTeam): void
    {
        $platform = Tool::factory()->create([
            'team_id' => $ownedByAgentTeam ? $this->team->id : null,
            'is_platform' => true,
            'type' => ToolType::McpHttp,
            'status' => ToolStatus::Active,
            'transport_config' => ['url' => 'https://shared.example.test'],
            'tool_definitions' => [$this->def('lookup')],
        ]);
        $this->agent->tools()->attach($platform->id, ['priority' => 1]);

        // If the shared server were listed, it would hand this team a new tool
        // and rewrite the row every other team reads.
        $this->servers([
            self::BANK_URL => [$this->def('transfer')],
            'https://shared.example.test' => [$this->def('lookup'), $this->def('injected')],
        ]);
        $log = Log::spy();

        $names = $this->resolvedNames();

        $this->assertSame([self::BANK_URL], $this->listedUrls);
        $this->assertContains('lookup', $names);
        $this->assertNotContains('injected', $names);
        $this->assertSame(['lookup'], array_column($platform->fresh()->tool_definitions, 'name'));
        $log->shouldNotHaveReceived('warning');
    }

    public function test_tool_owned_by_another_team_is_never_listed_or_rewritten(): void
    {
        $other = $this->makeTeam('Other Team');
        $foreign = Tool::factory()->create([
            'team_id' => $other->id,
            'type' => ToolType::McpHttp,
            'status' => ToolStatus::Active,
            'transport_config' => ['url' => 'https://other.example.test'],
            'tool_definitions' => [$this->def('ledger')],
        ]);
        $this->agent->tools()->attach($foreign->id, ['priority' => 1]);

        $this->servers([
            self::BANK_URL => [$this->def('transfer')],
            'https://other.example.test' => [$this->def('ledger'), $this->def('wipe')],
        ]);

        $this->resolvedNames();

        $this->assertNotContains('https://other.example.test', $this->listedUrls);
        $this->assertSame(['ledger'], array_column($foreign->fresh()->tool_definitions, 'name'));
    }

    public function test_second_run_inside_the_window_does_not_list_again(): void
    {
        $this->servers([self::BANK_URL => [$this->def('transfer'), $this->def('refund')]]);

        $this->resolvedNames();
        $names = $this->resolvedNames();

        $this->assertCount(1, $this->listedUrls);
        $this->assertContains('refund', $names, 'the stored (already refreshed) definitions are used');

        $this->travel(301)->seconds();
        $this->resolvedNames();

        $this->assertCount(2, $this->listedUrls);
    }

    public function test_failed_attempt_also_starts_the_window(): void
    {
        $this->servers([self::BANK_URL => new RuntimeException('timed out')]);

        $this->resolvedNames();
        $this->assertContains('transfer', $this->resolvedNames());

        $this->assertCount(1, $this->listedUrls, 'a failing server costs one attempt per window, not one per run');
    }

    public function test_zero_interval_lists_on_every_run(): void
    {
        config(['tools.refresh_definitions_min_interval' => 0]);
        $this->servers([self::BANK_URL => [$this->def('transfer')]]);

        $this->resolvedNames();
        $this->resolvedNames();

        $this->assertCount(2, $this->listedUrls);
    }

    public function test_approval_replay_does_not_refresh(): void
    {
        $this->servers([self::BANK_URL => [$this->def('transfer'), $this->def('refund')]]);

        $names = collect(app(ResolveAgentToolsAction::class)->resolveToolForReplay($this->agent, $this->tool->id, []))
            ->map(fn ($t) => $t->name())
            ->all();

        $this->assertSame([], $this->listedUrls);
        $this->assertSame(['transfer'], $names);
    }

    public function test_cache_outage_skips_the_refresh_and_keeps_stored_definitions(): void
    {
        $this->servers([self::BANK_URL => [$this->def('transfer'), $this->def('refund')]]);
        Cache::extend('down', fn () => Cache::repository(new class extends ArrayStore
        {
            public function add($key, $value, $seconds)
            {
                throw new RuntimeException('cache unavailable');
            }
        }));
        config(['cache.stores.down' => ['driver' => 'down'], 'cache.default' => 'down']);
        Log::spy();

        $names = $this->resolvedNames();

        $this->assertSame(['transfer'], $names);
        $this->assertSame([], $this->listedUrls, 'without the window the server is not listed');
        $this->assertSame(['transfer'], array_column($this->tool->fresh()->tool_definitions, 'name'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'refresh failed'));
    }

    public function test_hanging_server_falls_back_to_stored_definitions_within_the_timeout(): void
    {
        config(['tools.refresh_definitions_timeout' => 1]);

        // Listening socket that is never accepted: connect succeeds, no reply ever comes.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, $errstr);
        $address = stream_socket_get_name($server, false);
        $this->tool->update(['transport_config' => ['url' => "http://{$address}"]]);

        $started = microtime(true);
        $names = $this->resolvedNames();
        $elapsed = microtime(true) - $started;

        fclose($server);

        $this->assertContains('transfer', $names);
        $this->assertSame(['transfer'], array_column($this->tool->fresh()->tool_definitions, 'name'));
        $this->assertLessThan(3.0, $elapsed, 'both transports together must stay inside the refresh timeout');
    }
}
