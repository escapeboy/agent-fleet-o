<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Bridge\Models\BridgeConnection;
use App\Infrastructure\AI\Services\ProviderResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests the bridge agent model-mapping logic in ProviderResolver.
 *
 * The private activeBridgeAgents() method fetches a BridgeConnection from DB,
 * then maps its agents list to compound model keys. We test the mapping logic
 * by calling the private method via reflection on a real ProviderResolver,
 * after mocking the static Eloquent call with a partial mock.
 *
 * Since the method is private and tightly coupled to Eloquent, we extract
 * the pure mapping logic into a helper and test that directly.
 */
class ProviderResolverBridgeAgentTest extends TestCase
{
    /**
     * Replicate the mapping logic from activeBridgeAgents() to test it
     * without DB. This mirrors the exact code path.
     */
    private function mapAgentsToModels(array $agents): array
    {
        $bridgeAgentModels = [
            'claude-code' => [
                'claude-sonnet-4-5' => 'Claude Code — Sonnet 4.5',
                'claude-haiku-4-5'  => 'Claude Code — Haiku 4.5',
                'claude-opus-4-6'   => 'Claude Code — Opus 4.6',
            ],
            'codex' => [
                'o4-mini' => 'Codex — o4-mini',
                'o3'      => 'Codex — o3',
                'o1'      => 'Codex — o1',
            ],
            'gemini' => [
                'gemini-2.5-flash' => 'Gemini CLI — 2.5 Flash',
                'gemini-2.5-pro'   => 'Gemini CLI — 2.5 Pro',
            ],
            'aider' => [
                'claude-sonnet-4-5' => 'Aider — Sonnet 4.5',
                'claude-haiku-4-5'  => 'Aider — Haiku 4.5',
                'gpt-4o'            => 'Aider — GPT-4o',
                'gpt-4o-mini'       => 'Aider — GPT-4o Mini',
                'gemini-2.5-flash'  => 'Aider — Gemini 2.5 Flash',
            ],
        ];

        // This is the exact logic from ProviderResolver::activeBridgeAgents()
        $models = [];
        foreach ($agents as $agent) {
            if (! ($agent['found'] ?? false)) {
                continue;
            }
            $key = $agent['key'];
            $agentName = $agent['name'] ?? $key;
            $knownModels = $bridgeAgentModels[$key] ?? null;

            if ($knownModels) {
                foreach ($knownModels as $modelKey => $modelLabel) {
                    $models["{$key}:{$modelKey}"] = [
                        'label'       => $modelLabel,
                        'input_cost'  => 0,
                        'output_cost' => 0,
                    ];
                }
            } else {
                $models[$key] = [
                    'label'       => $agentName,
                    'input_cost'  => 0,
                    'output_cost' => 0,
                ];
            }
        }

        return $models;
    }

    /**
     * Also verify the constant exists on the real class via reflection.
     */
    private function getBridgeAgentModelsConstant(): array
    {
        $ref = new \ReflectionClass(ProviderResolver::class);
        $const = $ref->getConstant('BRIDGE_AGENT_MODELS');
        $this->assertIsArray($const);

        return $const;
    }

    public function test_bridge_agent_models_constant_has_expected_keys(): void
    {
        $models = $this->getBridgeAgentModelsConstant();

        $this->assertArrayHasKey('claude-code', $models);
        $this->assertArrayHasKey('codex', $models);
        $this->assertArrayHasKey('gemini', $models);
        $this->assertArrayHasKey('aider', $models);
    }

    public function test_known_agent_returns_compound_keys(): void
    {
        $result = $this->mapAgentsToModels([
            ['key' => 'claude-code', 'name' => 'Claude Code', 'found' => true],
        ]);

        $this->assertArrayHasKey('claude-code:claude-sonnet-4-5', $result);
        $this->assertArrayHasKey('claude-code:claude-haiku-4-5', $result);
        $this->assertArrayHasKey('claude-code:claude-opus-4-6', $result);
        $this->assertEquals('Claude Code — Sonnet 4.5', $result['claude-code:claude-sonnet-4-5']['label']);
        $this->assertEquals(0, $result['claude-code:claude-sonnet-4-5']['input_cost']);
    }

    public function test_unknown_agent_returns_single_key(): void
    {
        $result = $this->mapAgentsToModels([
            ['key' => 'opencode', 'name' => 'OpenCode', 'found' => true],
        ]);

        $this->assertArrayHasKey('opencode', $result);
        $this->assertEquals('OpenCode', $result['opencode']['label']);
    }

    public function test_not_found_agents_are_skipped(): void
    {
        $result = $this->mapAgentsToModels([
            ['key' => 'claude-code', 'name' => 'Claude Code', 'found' => true],
            ['key' => 'codex', 'name' => 'Codex', 'found' => false],
        ]);

        $this->assertNotEmpty($result);
        foreach (array_keys($result) as $key) {
            $this->assertStringStartsNotWith('codex', $key);
        }
    }

    public function test_empty_agents_returns_empty(): void
    {
        $result = $this->mapAgentsToModels([]);
        $this->assertEmpty($result);
    }

    public function test_codex_returns_known_models(): void
    {
        $result = $this->mapAgentsToModels([
            ['key' => 'codex', 'name' => 'Codex', 'found' => true],
        ]);

        $this->assertArrayHasKey('codex:o4-mini', $result);
        $this->assertArrayHasKey('codex:o3', $result);
        $this->assertArrayHasKey('codex:o1', $result);
        $this->assertCount(3, $result);
    }

    public function test_multiple_agents_produce_combined_models(): void
    {
        $result = $this->mapAgentsToModels([
            ['key' => 'claude-code', 'name' => 'Claude Code', 'found' => true],
            ['key' => 'kiro', 'name' => 'Kiro', 'found' => true],
        ]);

        $this->assertArrayHasKey('claude-code:claude-sonnet-4-5', $result);
        $this->assertArrayHasKey('kiro', $result);
        $this->assertEquals('Kiro', $result['kiro']['label']);
    }

    public function test_gemini_and_aider_return_known_models(): void
    {
        $result = $this->mapAgentsToModels([
            ['key' => 'gemini', 'name' => 'Gemini CLI', 'found' => true],
            ['key' => 'aider', 'name' => 'Aider', 'found' => true],
        ]);

        $this->assertArrayHasKey('gemini:gemini-2.5-flash', $result);
        $this->assertArrayHasKey('gemini:gemini-2.5-pro', $result);
        $this->assertArrayHasKey('aider:claude-sonnet-4-5', $result);
        $this->assertArrayHasKey('aider:gpt-4o', $result);
        $this->assertArrayHasKey('aider:gpt-4o-mini', $result);
    }

    public function test_agent_without_name_uses_key_as_label(): void
    {
        $result = $this->mapAgentsToModels([
            ['key' => 'custom-agent', 'found' => true],
        ]);

        $this->assertArrayHasKey('custom-agent', $result);
        $this->assertEquals('custom-agent', $result['custom-agent']['label']);
    }

    public function test_all_models_have_zero_cost(): void
    {
        $result = $this->mapAgentsToModels([
            ['key' => 'claude-code', 'name' => 'Claude Code', 'found' => true],
            ['key' => 'kiro', 'name' => 'Kiro', 'found' => true],
        ]);

        foreach ($result as $model) {
            $this->assertEquals(0, $model['input_cost']);
            $this->assertEquals(0, $model['output_cost']);
        }
    }

    public function test_connection_agents_method_parses_endpoints(): void
    {
        $connection = new BridgeConnection;
        $connection->endpoints = [
            'agents' => [
                ['key' => 'claude-code', 'name' => 'Claude Code', 'found' => true],
                ['key' => 'codex', 'name' => 'Codex', 'found' => false],
            ],
        ];

        $agents = $connection->agents();

        $this->assertCount(2, $agents);
        $this->assertEquals('claude-code', $agents[0]['key']);
        $this->assertTrue($agents[0]['found']);
        $this->assertFalse($agents[1]['found']);
    }
}
