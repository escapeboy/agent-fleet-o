<?php

namespace Tests\Unit\Domain\Tool;

use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\ToolTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifies that tools.network_policy.allowed_domains flows through ToolTranslator
 * into the options passed to BrowserSidecarClient and BrowserUseCloudClient.
 *
 * The sidecar + cloud API already enforce allowed_domains at the browser-use level —
 * this test covers the wiring in ToolTranslator that forwards the stored policy.
 */
class ToolTranslatorBrowserPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['agent.browser_sandbox_url' => 'http://browser_sidecar:8090']);
        config(['agent.browser_sandbox_secret' => 'test-secret']);
    }

    public function test_build_browser_tools_forwards_allowed_domains_when_policy_set(): void
    {
        config(['agent.browser_sandbox_mode' => 'sidecar']);
        Http::fake([
            'http://browser_sidecar:8090/run' => Http::response([
                'status' => 'success',
                'output' => 'done',
                'steps_taken' => 1,
                'duration_ms' => 100,
                'screenshots' => [],
                'urls_visited' => [],
            ], 200),
        ]);

        $tool = Tool::factory()->create([
            'type' => ToolType::BuiltIn,
            'transport_config' => ['kind' => 'browser'],
            'network_policy' => [
                'allowed_domains' => ['example.com', '*.trusted.io'],
                'default_action' => 'deny',
            ],
        ]);

        $prismTools = app(ToolTranslator::class)->toPrismTools($tool);
        $this->assertCount(1, $prismTools);
        $prismTools[0]->handle('search for cats');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_ends_with($request->url(), '/run')
                && isset($body['allowed_domains'])
                && $body['allowed_domains'] === ['example.com', '*.trusted.io'];
        });
    }

    public function test_build_browser_tools_omits_allowed_domains_when_policy_null(): void
    {
        config(['agent.browser_sandbox_mode' => 'sidecar']);
        Http::fake([
            'http://browser_sidecar:8090/run' => Http::response([
                'status' => 'success',
                'output' => 'done',
                'steps_taken' => 1,
                'duration_ms' => 100,
                'screenshots' => [],
                'urls_visited' => [],
            ], 200),
        ]);

        $tool = Tool::factory()->create([
            'type' => ToolType::BuiltIn,
            'transport_config' => ['kind' => 'browser'],
            'network_policy' => null,
        ]);

        $prismTools = app(ToolTranslator::class)->toPrismTools($tool);
        $prismTools[0]->handle('search for cats');

        Http::assertSent(fn ($request) => ! array_key_exists('allowed_domains', $request->data()));
    }

    public function test_build_browser_tools_filters_out_non_string_allowed_domains(): void
    {
        config(['agent.browser_sandbox_mode' => 'sidecar']);
        Http::fake([
            'http://browser_sidecar:8090/run' => Http::response([
                'status' => 'success',
                'output' => 'done',
                'steps_taken' => 1,
                'duration_ms' => 100,
                'screenshots' => [],
                'urls_visited' => [],
            ], 200),
        ]);

        $tool = Tool::factory()->create([
            'type' => ToolType::BuiltIn,
            'transport_config' => ['kind' => 'browser'],
            'network_policy' => ['allowed_domains' => ['ok.com', '', 42, null]],
        ]);

        $prismTools = app(ToolTranslator::class)->toPrismTools($tool);
        $prismTools[0]->handle('search for cats');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['allowed_domains'])
                && $body['allowed_domains'] === ['ok.com'];
        });
    }

    public function test_build_browser_use_cloud_tools_forwards_allowed_domains_in_v2_payload(): void
    {
        Http::fake([
            'https://api.browser-use.com/api/v2/tasks' => Http::response([
                'error' => 'short-circuit',
            ], 400),
        ]);

        $tool = Tool::factory()->create([
            'type' => ToolType::BuiltIn,
            'transport_config' => ['kind' => 'browser_use_cloud'],
            'credentials' => ['api_key' => 'bu-test-key'],
            'network_policy' => ['allowed_domains' => ['corp.example.com']],
        ]);

        $prismTools = app(ToolTranslator::class)->toPrismTools($tool);
        $result = $prismTools[0]->handle('search for dogs');

        // Intentional failure — we're validating the outbound payload, not the return.
        $this->assertStringContainsString('Error', $result);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/api/v2/tasks')) {
                return false;
            }
            $body = $request->data();

            return isset($body['allowedDomains'])
                && $body['allowedDomains'] === ['corp.example.com'];
        });
    }
}
