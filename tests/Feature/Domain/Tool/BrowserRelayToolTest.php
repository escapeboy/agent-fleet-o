<?php

namespace Tests\Feature\Domain\Tool;

use App\Domain\Tool\Enums\BuiltInToolKind;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\ToolTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrowserRelayToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_relay_kind_exists_in_enum(): void
    {
        $this->assertSame('browser_relay', BuiltInToolKind::BrowserRelay->value);
        $this->assertSame('Browser Relay (via relay agent)', BuiltInToolKind::BrowserRelay->label());
    }

    public function test_browser_relay_tool_returns_one_prism_tool(): void
    {
        $tool = Tool::factory()->create([
            'type' => ToolType::BuiltIn,
            'transport_config' => ['kind' => 'browser_relay'],
        ]);

        $prismTools = app(ToolTranslator::class)->toPrismTools($tool);

        $this->assertCount(1, $prismTools);
        $this->assertSame('browser_relay_execute', $prismTools[0]->name());
    }

    public function test_browser_relay_inert_tool_returns_error_when_dispatcher_not_bound(): void
    {
        $tool = Tool::factory()->create([
            'type' => ToolType::BuiltIn,
            'transport_config' => ['kind' => 'browser_relay'],
        ]);

        $prismTools = app(ToolTranslator::class)->toPrismTools($tool);

        $result = $prismTools[0]->handle('browser_navigate', '{"url":"https://example.com"}');

        $this->assertStringContainsString('Error', $result);
    }

    public function test_browser_relay_tool_dispatches_when_dispatcher_bound(): void
    {
        $dispatched = [];

        $this->app->bind('browser_relay.dispatcher', function () use (&$dispatched): callable {
            return function (string $teamId, string $toolName, array $params) use (&$dispatched): array {
                $dispatched[] = compact('teamId', 'toolName', 'params');

                return [['type' => 'text', 'text' => 'Page title: FleetQ Dashboard']];
            };
        });

        $tool = Tool::factory()->create([
            'type' => ToolType::BuiltIn,
            'transport_config' => ['kind' => 'browser_relay'],
        ]);

        $prismTools = app(ToolTranslator::class)->toPrismTools($tool);

        $this->assertCount(1, $prismTools);

        $result = $prismTools[0]->handle('browser_navigate', '{"url":"https://fleetq.net"}');

        $this->assertSame('Page title: FleetQ Dashboard', $result);
        $this->assertCount(1, $dispatched);
        $this->assertSame('browser_navigate', $dispatched[0]['toolName']);
        $this->assertSame(['url' => 'https://fleetq.net'], $dispatched[0]['params']);
    }

    public function test_browser_relay_tool_handles_empty_params(): void
    {
        $this->app->bind('browser_relay.dispatcher', fn () => function (string $teamId, string $toolName, array $params): array {
            $this->assertSame([], $params);

            return [['type' => 'text', 'text' => 'snapshot output']];
        });

        $tool = Tool::factory()->create([
            'type' => ToolType::BuiltIn,
            'transport_config' => ['kind' => 'browser_relay'],
        ]);

        $prismTools = app(ToolTranslator::class)->toPrismTools($tool);

        $result = $prismTools[0]->handle('browser_snapshot', null);

        $this->assertSame('snapshot output', $result);
    }

    public function test_browser_relay_tool_returns_json_for_non_text_result(): void
    {
        $this->app->bind('browser_relay.dispatcher', fn () => function (string $teamId, string $toolName, array $params): array {
            return [['status' => 'ok', 'code' => 200]];
        });

        $tool = Tool::factory()->create([
            'type' => ToolType::BuiltIn,
            'transport_config' => ['kind' => 'browser_relay'],
        ]);

        $prismTools = app(ToolTranslator::class)->toPrismTools($tool);

        $result = $prismTools[0]->handle('browser_screenshot', null);

        $this->assertStringContainsString('ok', $result);
    }

    public function test_browser_relay_tool_handles_runtime_exception(): void
    {
        $this->app->bind('browser_relay.dispatcher', fn () => function (string $teamId, string $toolName, array $params): array {
            throw new \RuntimeException('Relay agent not connected');
        });

        $tool = Tool::factory()->create([
            'type' => ToolType::BuiltIn,
            'transport_config' => ['kind' => 'browser_relay'],
        ]);

        $prismTools = app(ToolTranslator::class)->toPrismTools($tool);

        $result = $prismTools[0]->handle('browser_navigate', '{"url":"https://example.com"}');

        $this->assertStringContainsString('Error: Relay agent not connected', $result);
    }
}
