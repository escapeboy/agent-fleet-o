<?php

namespace Tests\Feature\Mcp;

use App\Domain\Shared\Contracts\AutoRegistersAsMcpTool;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Connectors\RssConnector;
use App\Domain\Signal\Connectors\WebhookConnector;
use App\Domain\Signal\Connectors\WebScrapingConnector;
use App\Mcp\Services\ConnectorMcpRegistrar;
use App\Mcp\Tools\Synthetic\SyntheticConnectorTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * Activepieces-inspired auto-MCP for opt-in connectors (build #3, Trendshift top-5 sprint).
 */
class AutoConnectorMcpTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        // Wipe any cached synthetic tool files left over from prior runs.
        $cacheDir = base_path(ConnectorMcpRegistrar::CACHE_DIR);
        if (File::isDirectory($cacheDir)) {
            File::deleteDirectory($cacheDir);
        }

        $this->team = Team::factory()->create();
        User::factory()->create(['current_team_id' => $this->team->id]);

        app()->instance('mcp.team_id', $this->team->id);
    }

    public function test_three_signal_connectors_implement_the_contract(): void
    {
        $rss = app(RssConnector::class);
        $webhook = app(WebhookConnector::class);
        $scrape = app(WebScrapingConnector::class);

        $this->assertInstanceOf(AutoRegistersAsMcpTool::class, $rss);
        $this->assertInstanceOf(AutoRegistersAsMcpTool::class, $webhook);
        $this->assertInstanceOf(AutoRegistersAsMcpTool::class, $scrape);

        $this->assertSame('signal.rss.poll', $rss->mcpName());
        $this->assertSame('signal.webhook.ingest', $webhook->mcpName());
        $this->assertSame('signal.webclaw.scrape', $scrape->mcpName());
    }

    public function test_registrar_discovers_and_caches_class_files_for_opted_in_connectors(): void
    {
        $registrar = app(ConnectorMcpRegistrar::class);

        $classes = $registrar->discoverToolClasses();

        // The 3 base signal connectors that opt in this sprint.
        $this->assertGreaterThanOrEqual(3, count($classes), 'Expected ≥3 synthesized tool classes');

        // Cache files exist on disk.
        $cacheDir = base_path(ConnectorMcpRegistrar::CACHE_DIR);
        $this->assertTrue(File::isDirectory($cacheDir));
        $files = File::files($cacheDir);
        $this->assertGreaterThanOrEqual(3, count($files));

        // Every returned class is loadable.
        foreach ($classes as $cls) {
            $this->assertTrue(class_exists($cls), "Generated class {$cls} should be loadable");
            $this->assertTrue(is_subclass_of($cls, SyntheticConnectorTool::class));
        }
    }

    public function test_synthesized_class_resolves_back_to_its_connector(): void
    {
        $registrar = app(ConnectorMcpRegistrar::class);
        $registrar->discoverToolClasses();

        // Find the synthesized class for RssConnector by name.
        $rssToolName = 'signal.rss.poll';
        $found = null;
        foreach (File::files(base_path(ConnectorMcpRegistrar::CACHE_DIR)) as $file) {
            $shortName = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            $fqcn = ConnectorMcpRegistrar::NAMESPACE.'\\'.$shortName;
            $tool = new $fqcn;
            if ($tool->name() === $rssToolName) {
                $found = $tool;
                break;
            }
        }

        $this->assertNotNull($found);
        $this->assertSame('signal.rss.poll', $found->name());
        $this->assertStringContainsString('RSS', $found->description());
    }

    public function test_invocation_without_team_returns_no_team_resolved_error(): void
    {
        app()->forgetInstance('mcp.team_id');
        app()->bind('mcp.team_id', fn () => null);

        $registrar = app(ConnectorMcpRegistrar::class);
        $registrar->discoverToolClasses();

        $tool = $this->findToolByName('signal.rss.poll');
        $this->assertNotNull($tool);

        $request = new Request(['url' => 'https://example.com/feed']);
        $response = $tool->handle($request);
        $payload = json_decode((string) $response->content(), true);

        $this->assertSame('no_team_resolved', $payload['error'] ?? null);
    }

    public function test_rss_invocation_returns_count_and_signal_ids(): void
    {
        Http::fake([
            'example.com/feed' => Http::response(
                "<?xml version='1.0' encoding='UTF-8'?><rss><channel>".
                '<item><title>Hello</title><link>https://example.com/post-1</link></item>'.
                '<item><title>World</title><link>https://example.com/post-2</link></item>'.
                '</channel></rss>',
                200,
            ),
        ]);

        $registrar = app(ConnectorMcpRegistrar::class);
        $registrar->discoverToolClasses();

        $tool = $this->findToolByName('signal.rss.poll');
        $this->assertNotNull($tool);

        $request = new Request(['url' => 'https://example.com/feed', 'tags' => ['test']]);
        $response = $tool->handle($request);
        $payload = json_decode((string) $response->content(), true);

        $this->assertSame(2, $payload['count']);
        $this->assertCount(2, $payload['signal_ids']);
    }

    public function test_clear_cache_removes_files_and_bindings(): void
    {
        $registrar = app(ConnectorMcpRegistrar::class);
        $registrar->discoverToolClasses();

        $cacheDir = base_path(ConnectorMcpRegistrar::CACHE_DIR);
        $this->assertTrue(File::isDirectory($cacheDir));

        $registrar->clearCache();

        $this->assertFalse(File::isDirectory($cacheDir));
    }

    public function test_artisan_command_reports_class_count(): void
    {
        $this->artisan('mcp:cache-connector-tools')
            ->expectsOutputToContain('synthetic MCP tool classes')
            ->assertExitCode(0);

        $this->artisan('mcp:cache-connector-tools', ['--clear' => true])
            ->expectsOutputToContain('Cleared synthetic MCP tool cache')
            ->assertExitCode(0);
    }

    private function findToolByName(string $name): ?SyntheticConnectorTool
    {
        foreach (File::files(base_path(ConnectorMcpRegistrar::CACHE_DIR)) as $file) {
            $shortName = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            $fqcn = ConnectorMcpRegistrar::NAMESPACE.'\\'.$shortName;
            if (class_exists($fqcn)) {
                $tool = new $fqcn;
                if ($tool->name() === $name) {
                    return $tool;
                }
            }
        }

        return null;
    }
}
