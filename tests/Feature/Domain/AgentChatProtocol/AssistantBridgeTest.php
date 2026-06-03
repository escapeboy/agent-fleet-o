<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\AgentChatProtocol;

use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Servers\AgentFleetServer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AssistantBridgeTest extends TestCase
{
    public function test_all_16_agent_chat_protocol_tools_are_registered_on_server(): void
    {
        $tools = (new ReflectionClass(AgentFleetServer::class))->getDefaultProperties()['tools'] ?? [];
        $acp = array_filter($tools, fn (string $class) => str_contains($class, 'AgentChatProtocol'));

        $this->assertCount(16, $acp, 'Expected 16 Agent Chat Protocol MCP tools registered on the server.');
    }

    public function test_agent_chat_protocol_tools_are_tier_annotated(): void
    {
        $tools = (new ReflectionClass(AgentFleetServer::class))->getDefaultProperties()['tools'] ?? [];
        $acpTools = array_filter($tools, fn (string $class) => str_contains($class, 'AgentChatProtocol'));

        $buckets = ['read' => 0, 'write' => 0, 'destructive' => 0];
        foreach ($acpTools as $toolClass) {
            $attrs = (new ReflectionClass($toolClass))->getAttributes(AssistantTool::class);
            $this->assertNotEmpty($attrs, "Tool {$toolClass} is missing #[AssistantTool] attribute.");
            $tier = $attrs[0]->newInstance()->tier;
            $this->assertArrayHasKey($tier, $buckets, "Unknown tier '{$tier}' on {$toolClass}.");
            $buckets[$tier]++;
        }

        $this->assertSame(6, $buckets['read'], 'Expected 6 read-tier ACP tools (added agentverse_search).');
        $this->assertSame(8, $buckets['write'], 'Expected 8 write-tier ACP tools (added external_agent_discover_a2a).');
        $this->assertSame(2, $buckets['destructive'], 'Expected 2 destructive-tier ACP tools.');
    }
}
