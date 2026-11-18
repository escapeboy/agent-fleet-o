<?php

namespace Tests\Unit\Domain\Workflow;

use App\Domain\Workflow\Services\FlowiseFlowConverter;
use PHPUnit\Framework\TestCase;

class FlowiseFlowConverterTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function agentflowFixture(): array
    {
        return [
            'nodes' => [
                [
                    'id' => 'startAgentflow_0',
                    'position' => ['x' => 10.4, 'y' => 20.6],
                    'data' => ['name' => 'startAgentflow', 'label' => 'Start', 'inputs' => ['startInputType' => 'chatInput']],
                ],
                [
                    'id' => 'llmAgentflow_0',
                    'position' => ['x' => 200, 'y' => 20],
                    'data' => [
                        'name' => 'llmAgentflow',
                        'label' => 'Summarise',
                        'inputs' => [
                            'llmModel' => 'chatOpenAI',
                            'llmModelConfig' => ['modelName' => 'gpt-4o-mini'],
                            'llmMessages' => [
                                ['role' => 'system', 'content' => 'You are a summariser.'],
                                ['role' => 'user', 'content' => 'Summarise {{question}}'],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'stickyNoteAgentflow_0',
                    'position' => ['x' => 0, 'y' => 300],
                    'data' => ['name' => 'stickyNoteAgentflow', 'label' => 'Note', 'inputs' => ['note' => 'Remember to test']],
                ],
                [
                    'id' => 'customFunctionAgentflow_0',
                    'position' => ['x' => 400, 'y' => 20],
                    'data' => ['name' => 'customFunctionAgentflow', 'label' => 'JS', 'inputs' => ['customFunctionJavascriptFunction' => 'return 1;']],
                ],
            ],
            'edges' => [
                ['source' => 'startAgentflow_0', 'target' => 'llmAgentflow_0', 'sourceHandle' => 'startAgentflow_0-output-startAgentflow'],
                ['source' => 'llmAgentflow_0', 'target' => 'customFunctionAgentflow_0', 'sourceHandle' => 'llmAgentflow_0-output-llmAgentflow'],
                ['source' => 'stickyNoteAgentflow_0', 'target' => 'llmAgentflow_0', 'sourceHandle' => 'x'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chatflowFixture(): array
    {
        return [
            'nodes' => [
                [
                    'id' => 'chatAnthropic_0',
                    'position' => ['x' => 0, 'y' => 0],
                    'data' => ['name' => 'chatAnthropic', 'label' => 'ChatAnthropic', 'category' => 'Chat Models', 'inputs' => ['modelName' => 'claude-sonnet-4-5']],
                ],
                [
                    'id' => 'chatPromptTemplate_0',
                    'position' => ['x' => 0, 'y' => 200],
                    'data' => ['name' => 'chatPromptTemplate', 'label' => 'Chat Prompt Template', 'category' => 'Prompts', 'inputs' => [
                        'systemMessagePrompt' => 'You are a pirate.',
                        'humanMessagePrompt' => '{text}',
                    ]],
                ],
                [
                    'id' => 'bufferMemory_0',
                    'position' => ['x' => 0, 'y' => 400],
                    'data' => ['name' => 'bufferMemory', 'label' => 'Buffer Memory', 'category' => 'Memory', 'inputs' => []],
                ],
                [
                    'id' => 'llmChain_0',
                    'position' => ['x' => 300, 'y' => 200],
                    'data' => ['name' => 'llmChain', 'label' => 'LLM Chain', 'category' => 'Chains', 'inputs' => []],
                ],
            ],
            'edges' => [
                ['source' => 'chatAnthropic_0', 'target' => 'llmChain_0'],
                ['source' => 'chatPromptTemplate_0', 'target' => 'llmChain_0'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    private function nodeById(array $envelope, string $id): array
    {
        foreach ($envelope['nodes'] as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }
        $this->fail("Node {$id} not found");
    }

    public function test_detects_flowise_exports_but_not_fleetq_envelopes(): void
    {
        $this->assertTrue(FlowiseFlowConverter::detects($this->agentflowFixture()));
        $this->assertTrue(FlowiseFlowConverter::detects($this->chatflowFixture()));

        $this->assertFalse(FlowiseFlowConverter::detects([
            'version' => '1.0',
            'workflow' => ['name' => 'x'],
            'nodes' => [['id' => 'a', 'type' => 'start']],
            'edges' => [],
        ]));
        $this->assertFalse(FlowiseFlowConverter::detects([
            'format_version' => '2.0',
            'workflow' => ['name' => 'x'],
            'nodes' => [],
            'edges' => [],
        ]));
        $this->assertFalse(FlowiseFlowConverter::detects(['nodes' => [['id' => 'a']], 'edges' => []]));
    }

    public function test_converts_agentflow_v2_chain(): void
    {
        $result = (new FlowiseFlowConverter)->convert($this->agentflowFixture(), 'My flow');
        $envelope = $result['envelope'];

        $this->assertSame('1.0', $envelope['version']);
        $this->assertSame('My flow', $envelope['workflow']['name']);
        $this->assertSame('flowise', $envelope['workflow']['settings']['source']);

        $start = $this->nodeById($envelope, 'startAgentflow_0');
        $this->assertSame('start', $start['type']);
        $this->assertSame(10, $start['position_x']);
        $this->assertSame(21, $start['position_y']);

        $llm = $this->nodeById($envelope, 'llmAgentflow_0');
        $this->assertSame('llm', $llm['type']);
        $this->assertSame('Summarise', $llm['label']);
        $this->assertSame('openai/gpt-4o-mini', $llm['config']['model']);
        $this->assertSame('You are a summariser.', $llm['config']['system_prompt']);
        $this->assertSame('Summarise {{question}}', $llm['config']['prompt_template']);

        $note = $this->nodeById($envelope, 'stickyNoteAgentflow_0');
        $this->assertSame('annotation', $note['type']);

        $todo = $this->nodeById($envelope, 'customFunctionAgentflow_0');
        $this->assertSame('template_transform', $todo['type']);
        $this->assertSame('TODO (Flowise: customFunctionAgentflow)', $todo['label']);
        $this->assertSame('return 1;', $todo['config']['flowise_inputs']['customFunctionJavascriptFunction']);

        // One end node appended, connected from the only sink.
        $ends = array_values(array_filter($envelope['nodes'], fn ($n) => $n['type'] === 'end'));
        $this->assertCount(1, $ends);
        $starts = array_values(array_filter($envelope['nodes'], fn ($n) => $n['type'] === 'start'));
        $this->assertCount(1, $starts);

        $pairs = array_map(fn ($e) => $e['source_node_id'].'>'.$e['target_node_id'], $envelope['edges']);
        $this->assertContains('startAgentflow_0>llmAgentflow_0', $pairs);
        $this->assertContains('llmAgentflow_0>customFunctionAgentflow_0', $pairs);
        $this->assertContains('customFunctionAgentflow_0>'.$ends[0]['id'], $pairs);
        // Sticky note edges are dropped.
        $this->assertNotContains('stickyNoteAgentflow_0>llmAgentflow_0', $pairs);
        $this->assertCount(3, $envelope['edges']);

        $warnings = implode("\n", $result['warnings']);
        $this->assertStringContainsString('customFunctionAgentflow', $warnings);
        $this->assertStringContainsString('{{', $warnings);
    }

    public function test_adds_start_node_and_handles_conditions_and_unknown_models(): void
    {
        $data = [
            'nodes' => [
                ['id' => 'c', 'position' => ['x' => 0, 'y' => 0], 'data' => ['name' => 'conditionAgentflow', 'label' => 'Check', 'inputs' => []]],
                ['id' => 'a', 'position' => ['x' => 0, 'y' => 0], 'data' => ['name' => 'agentAgentflow', 'label' => 'Agent', 'inputs' => [
                    'agentModel' => 'chatOllama',
                    'agentModelConfig' => ['modelName' => 'llama3'],
                    'agentMessages' => [['role' => 'system', 'content' => 'Be kind']],
                    'agentUserMessage' => 'Hello',
                    'agentTools' => [['agentSelectedTool' => 'calculator']],
                ]]],
                ['id' => 'h', 'position' => ['x' => 0, 'y' => 0], 'data' => ['name' => 'httpAgentflow', 'label' => 'Call', 'inputs' => [
                    'method' => 'POST',
                    'url' => 'https://example.com/hook',
                    'headers' => [['key' => 'X-Test', 'value' => '1']],
                    'body' => '{"a":1}',
                ]]],
            ],
            'edges' => [
                ['source' => 'c', 'target' => 'h', 'sourceHandle' => 'c-output-1'],
                ['source' => 'c', 'target' => 'a', 'sourceHandle' => 'c-output-0'],
            ],
        ];

        $result = (new FlowiseFlowConverter)->convert($data);
        $envelope = $result['envelope'];

        $starts = array_values(array_filter($envelope['nodes'], fn ($n) => $n['type'] === 'start'));
        $this->assertCount(1, $starts);
        $startEdges = array_values(array_filter($envelope['edges'], fn ($e) => $e['source_node_id'] === $starts[0]['id']));
        $this->assertCount(1, $startEdges);
        $this->assertSame('c', $startEdges[0]['target_node_id']);

        $this->assertSame('conditional', $this->nodeById($envelope, 'c')['type']);
        $condEdges = array_values(array_filter($envelope['edges'], fn ($e) => $e['source_node_id'] === 'c'));
        $this->assertCount(2, $condEdges);
        $this->assertSame('a', $condEdges[0]['target_node_id']);
        $this->assertSame('Flowise output 1', $condEdges[0]['label']);
        $this->assertFalse($condEdges[0]['is_default']);
        $this->assertSame('Flowise output 2', $condEdges[1]['label']);
        $this->assertTrue($condEdges[1]['is_default']);

        $agent = $this->nodeById($envelope, 'a');
        $this->assertSame('llm', $agent['type']);
        $this->assertArrayNotHasKey('model', $agent['config']);
        $this->assertSame('Be kind', $agent['config']['system_prompt']);
        $this->assertSame('Hello', $agent['config']['prompt_template']);

        $http = $this->nodeById($envelope, 'h');
        $this->assertSame('http_request', $http['type']);
        $this->assertSame('POST', $http['config']['method']);
        $this->assertSame(['X-Test' => '1'], $http['config']['headers']);
        $this->assertSame('{"a":1}', $http['config']['body']);

        $warnings = implode("\n", $result['warnings']);
        $this->assertStringContainsString('chatOllama', $warnings);
        $this->assertStringContainsString('tools', $warnings);
        $this->assertStringContainsString('condition', strtolower($warnings));
    }

    public function test_converts_legacy_chatflow(): void
    {
        $result = (new FlowiseFlowConverter)->convert($this->chatflowFixture());
        $envelope = $result['envelope'];

        $types = array_map(fn ($n) => $n['type'], $envelope['nodes']);
        $this->assertSame(['start', 'llm', 'end'], $types);
        $this->assertCount(2, $envelope['edges']);

        $llm = $envelope['nodes'][1];
        $this->assertSame('anthropic/claude-sonnet-4-5', $llm['config']['model']);
        $this->assertSame('You are a pirate.', $llm['config']['system_prompt']);
        $this->assertSame('{text}', $llm['config']['prompt_template']);

        $warnings = implode("\n", $result['warnings']);
        $this->assertStringContainsString('Buffer Memory', $warnings);
    }

    public function test_rejects_more_than_500_nodes(): void
    {
        $nodes = [];
        for ($i = 0; $i < 501; $i++) {
            $nodes[] = ['id' => "n{$i}", 'data' => ['name' => 'llmAgentflow', 'inputs' => []]];
        }

        $this->expectException(\InvalidArgumentException::class);
        (new FlowiseFlowConverter)->convert(['nodes' => $nodes, 'edges' => []]);
    }
}
