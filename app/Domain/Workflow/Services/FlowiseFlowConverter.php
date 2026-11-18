<?php

namespace App\Domain\Workflow\Services;

/**
 * Converts a Flowise export (Agentflow V2 or legacy chatflow JSON) into a
 * FleetQ v1 workflow export envelope that ImportWorkflowAction can import.
 *
 * Pure transformation: no database or HTTP access. Anything that cannot be
 * carried over is reported in the returned warnings list.
 */
class FlowiseFlowConverter
{
    public const MAX_NODES = 500;

    /** @var array<string, string> Flowise chat model component name => FleetQ provider prefix */
    private const PROVIDER_MAP = [
        'chatOpenAI' => 'openai',
        'chatAnthropic' => 'anthropic',
        'chatGoogleGenerativeAI' => 'google',
    ];

    /** @var list<string> Config keys holding text copied verbatim from Flowise */
    private const TEXT_KEYS = ['system_prompt', 'prompt_template', 'template', 'url', 'body', 'query_template', 'prompt'];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * Whether the decoded data looks like a Flowise export rather than a FleetQ envelope.
     *
     * @param  array<mixed>  $data
     */
    public static function detects(array $data): bool
    {
        if (array_key_exists('workflow', $data)) {
            return false;
        }

        $nodes = $data['nodes'] ?? null;
        if (! is_array($nodes) || $nodes === [] || ! array_is_list($nodes)) {
            return false;
        }

        if (! isset($data['edges']) || ! is_array($data['edges'])) {
            return false;
        }

        foreach ($nodes as $node) {
            if (! is_array($node) || ! isset($node['data']) || ! is_array($node['data'])
                || ! isset($node['data']['name']) || ! is_string($node['data']['name'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<mixed>  $data
     * @return array{envelope: array{version: string, workflow: array{name: string, description: string, settings: array{source: string}}, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}, warnings: list<string>}
     *
     * @throws \InvalidArgumentException
     */
    public function convert(array $data, ?string $name = null): array
    {
        $this->warnings = [];

        /** @var list<array<string, mixed>> $flowiseNodes */
        $flowiseNodes = array_values(array_filter(
            is_array($data['nodes'] ?? null) ? $data['nodes'] : [],
            'is_array',
        ));
        /** @var list<array<string, mixed>> $flowiseEdges */
        $flowiseEdges = array_values(array_filter(
            is_array($data['edges'] ?? null) ? $data['edges'] : [],
            'is_array',
        ));

        if (count($flowiseNodes) > self::MAX_NODES) {
            throw new \InvalidArgumentException('Flowise flow exceeds the maximum of '.self::MAX_NODES.' nodes.');
        }

        $isAgentflow = false;
        foreach ($flowiseNodes as $node) {
            if (str_ends_with($this->nodeName($node), 'Agentflow')) {
                $isAgentflow = true;
                break;
            }
        }

        [$nodes, $edges] = $isAgentflow
            ? $this->convertAgentflow($flowiseNodes, $flowiseEdges)
            : $this->convertChatflow($flowiseNodes);

        if ($this->containsVariables($nodes)) {
            $this->warnings[] = 'Prompts contain Flowise {{...}} variable placeholders; they were copied unchanged and must be checked against FleetQ context keys.';
        }

        return [
            'envelope' => [
                'version' => '1.0',
                'workflow' => [
                    'name' => $name ?? 'Imported Flowise flow',
                    'description' => 'Imported from Flowise',
                    'settings' => ['source' => 'flowise'],
                ],
                'nodes' => $nodes,
                'edges' => $edges,
            ],
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $flowiseNodes
     * @param  list<array<string, mixed>>  $flowiseEdges
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function convertAgentflow(array $flowiseNodes, array $flowiseEdges): array
    {
        $nodes = [];
        $types = [];
        $labels = [];
        $hasStart = false;

        foreach ($flowiseNodes as $index => $fNode) {
            $id = $this->nodeId($fNode, $index);
            $node = $this->convertAgentflowNode($fNode, $id);
            $node['order'] = $index + 1;
            $nodes[] = $node;
            $types[$id] = $node['type'];
            $labels[$id] = $node['label'];
            if ($node['type'] === 'start') {
                $hasStart = true;
            }
        }

        // Collect edges between known nodes, dropping annotation edges.
        $outgoing = [];
        $edgeIndex = 0;
        foreach ($flowiseEdges as $fEdge) {
            $source = is_scalar($fEdge['source'] ?? null) ? (string) $fEdge['source'] : '';
            $target = is_scalar($fEdge['target'] ?? null) ? (string) $fEdge['target'] : '';
            if (! isset($types[$source], $types[$target])) {
                continue;
            }
            if ($types[$source] === 'annotation' || $types[$target] === 'annotation') {
                continue;
            }
            $handle = is_string($fEdge['sourceHandle'] ?? null) ? $fEdge['sourceHandle'] : '';
            $outgoing[$source][] = ['target' => $target, 'handle' => $handle, 'index' => $edgeIndex++];
        }

        $edges = [];
        $hasIncoming = [];
        foreach ($outgoing as $source => $targets) {
            $isConditional = $types[$source] === 'conditional';
            if ($isConditional) {
                usort($targets, function (array $a, array $b): int {
                    $cmp = $this->handleIndex($a['handle']) <=> $this->handleIndex($b['handle']);

                    return $cmp !== 0 ? $cmp : $a['index'] <=> $b['index'];
                });
            }

            if ($types[$source] === 'human_task'
                && count(array_unique(array_column($targets, 'handle'))) > 1) {
                $this->warnings[] = "Human input '{$labels[$source]}': its proceed/reject branches were imported as plain edges and would both run; add a condition on the response in FleetQ.";
            }

            $last = count($targets) - 1;
            foreach ($targets as $i => $t) {
                // Label by the Flowise output index so unconnected outputs don't shift the numbering.
                $outputIndex = $this->handleIndex($t['handle']);
                $edges[] = [
                    'source_node_id' => (string) $source,
                    'target_node_id' => $t['target'],
                    'label' => $isConditional
                        ? 'Flowise output '.($outputIndex === PHP_INT_MAX ? $i + 1 : $outputIndex + 1)
                        : null,
                    'is_default' => $isConditional && $i === $last,
                    'sort_order' => $i,
                ];
                $hasIncoming[$t['target']] = true;
            }
        }

        $flowNodeIds = array_keys(array_filter($types, fn (string $t) => $t !== 'annotation'));

        if (! $hasStart) {
            $startId = $this->uniqueId('flowise_start', $types);
            $roots = array_values(array_filter($flowNodeIds, fn ($id) => ! isset($hasIncoming[$id])));
            array_unshift($nodes, [
                'id' => $startId,
                'type' => 'start',
                'label' => 'Start',
                'position_x' => 0,
                'position_y' => 0,
                'config' => [],
                'order' => 0,
            ]);
            $types[$startId] = 'start';
            foreach ($roots as $i => $rootId) {
                $edges[] = [
                    'source_node_id' => $startId,
                    'target_node_id' => (string) $rootId,
                    'label' => null,
                    'is_default' => false,
                    'sort_order' => $i,
                ];
            }
        }

        $sinks = array_values(array_filter($flowNodeIds, fn ($id) => ! isset($outgoing[$id])));
        if ($sinks !== []) {
            $endId = $this->uniqueId('flowise_end', $types);
            $maxX = 0;
            foreach ($nodes as $n) {
                $maxX = max($maxX, (int) $n['position_x']);
            }
            $nodes[] = [
                'id' => $endId,
                'type' => 'end',
                'label' => 'End',
                'position_x' => $maxX + 300,
                'position_y' => 0,
                'config' => [],
                'order' => count($flowiseNodes) + 1,
            ];
            foreach ($sinks as $sinkId) {
                $edges[] = [
                    'source_node_id' => (string) $sinkId,
                    'target_node_id' => $endId,
                    'label' => null,
                    'is_default' => false,
                    'sort_order' => 0,
                ];
            }
        }

        return [$nodes, $edges];
    }

    /**
     * @param  array<string, mixed>  $fNode
     * @return array<string, mixed>
     */
    private function convertAgentflowNode(array $fNode, string $id): array
    {
        $name = $this->nodeName($fNode);
        $inputs = $this->inputs($fNode);
        $label = $this->nodeLabel($fNode, $name);

        $type = 'template_transform';
        $config = [];

        switch ($name) {
            case 'startAgentflow':
                $type = 'start';
                $config = [];
                break;

            case 'llmAgentflow':
            case 'agentAgentflow':
                $prefix = $name === 'llmAgentflow' ? 'llm' : 'agent';
                $type = 'llm';
                $config = $this->llmConfig(
                    $inputs[$prefix.'Messages'] ?? [],
                    $inputs[$prefix.'UserMessage'] ?? null,
                    $inputs[$prefix.'Model'] ?? null,
                    $this->modelName($inputs[$prefix.'ModelConfig'] ?? null),
                    $label,
                );
                if ($name === 'agentAgentflow' && ! empty($inputs['agentTools'])) {
                    $this->warnings[] = "Agent '{$label}' used Flowise tools; tools must be re-attached manually in FleetQ.";
                }
                break;

            case 'httpAgentflow':
                $type = 'http_request';
                $config = [
                    'method' => strtoupper(is_string($inputs['method'] ?? null) ? $inputs['method'] : 'GET'),
                    'url' => $this->urlWithQuery(
                        is_string($inputs['url'] ?? null) ? $inputs['url'] : '',
                        $this->headers($inputs['queryParams'] ?? []),
                    ),
                    'headers' => $this->headers($inputs['headers'] ?? []),
                ];
                $body = $inputs['body'] ?? null;
                if (is_array($body)) {
                    // Form bodies are exported as a list of {key, value} pairs.
                    $fields = array_is_list($body) ? $this->headers($body) : $body;
                    if (($inputs['bodyType'] ?? null) === 'xWwwFormUrlencoded') {
                        $body = http_build_query($fields);
                        $config['headers']['Content-Type'] ??= 'application/x-www-form-urlencoded';
                    } else {
                        $body = (string) json_encode($fields);
                    }
                }
                if (is_string($body) && $body !== '') {
                    $config['body'] = $body;
                }
                break;

            case 'retrieverAgentflow':
                $type = 'knowledge_retrieval';
                $query = $inputs['retrieverQuery'] ?? null;
                // Without a query the executor falls back to its own default.
                $config = is_string($query) && $query !== '' ? ['query_template' => $query] : [];
                $this->warnings[] = "Retriever '{$label}': choose a knowledge_base_id in FleetQ; Flowise document stores were not imported.";
                break;

            case 'humanInputAgentflow':
                $type = 'human_task';
                $description = $inputs['humanInputDescription'] ?? null;
                $config = [
                    'prompt' => is_string($description) && $description !== '' ? $description : $label,
                    'form_schema' => [
                        'fields' => [
                            [
                                'name' => 'approved',
                                'type' => 'select',
                                'label' => 'Approve?',
                                'required' => true,
                                'options' => [
                                    ['value' => 'yes', 'label' => 'Yes'],
                                    ['value' => 'no', 'label' => 'No'],
                                ],
                            ],
                        ],
                    ],
                ];
                break;

            case 'directReplyAgentflow':
                $type = 'template_transform';
                $message = $inputs['directReplyMessage'] ?? '';
                $config = ['template' => is_string($message) ? $message : ''];
                break;

            case 'conditionAgentflow':
            case 'conditionAgentAgentflow':
                $type = 'conditional';
                $config = [];
                $this->warnings[] = "Condition '{$label}': conditions must be re-entered on the outgoing edges in FleetQ.";
                break;

            case 'stickyNoteAgentflow':
                $type = 'annotation';
                $note = $inputs['note'] ?? '';
                $config = ['text' => is_string($note) ? $note : ''];
                break;

            default:
                $type = 'template_transform';
                $label = "TODO (Flowise: {$name})";
                $config = ['template' => '', 'flowise_inputs' => $inputs];
                $this->warnings[] = "Flowise node '{$name}' has no FleetQ equivalent; a placeholder template node was created.";
        }

        return [
            'id' => $id,
            'type' => $type,
            'label' => $label,
            'position_x' => $this->coordinate($fNode, 'x'),
            'position_y' => $this->coordinate($fNode, 'y'),
            'config' => $config,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $flowiseNodes
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function convertChatflow(array $flowiseNodes): array
    {
        $modelNode = null;
        $promptNode = null;
        $chainNode = null;
        $skipped = [];

        foreach ($flowiseNodes as $fNode) {
            $category = is_string($fNode['data']['category'] ?? null) ? $fNode['data']['category'] : '';
            $name = $this->nodeName($fNode);

            if ($modelNode === null && $category === 'Chat Models') {
                $modelNode = $fNode;

                continue;
            }
            if ($promptNode === null && in_array($name, ['chatPromptTemplate', 'promptTemplate'], true)) {
                $promptNode = $fNode;

                continue;
            }
            if ($category === 'Chains') {
                $chainNode ??= $fNode;

                continue;
            }

            $skipped[] = $this->nodeLabel($fNode, $name);
        }

        $messages = [];
        $userMessage = null;
        if ($promptNode !== null) {
            $inputs = $this->inputs($promptNode);
            if ($this->nodeName($promptNode) === 'chatPromptTemplate') {
                if (is_string($inputs['systemMessagePrompt'] ?? null)) {
                    $messages[] = ['role' => 'system', 'content' => $inputs['systemMessagePrompt']];
                }
                $userMessage = $inputs['humanMessagePrompt'] ?? null;
            } else {
                $userMessage = $inputs['template'] ?? null;
            }
        }

        // Conversation chains carry their system prompt on the chain node itself.
        $chainSystem = $chainNode !== null ? ($this->inputs($chainNode)['systemMessagePrompt'] ?? null) : null;
        if ($messages === [] && is_string($chainSystem) && $chainSystem !== '') {
            $messages[] = ['role' => 'system', 'content' => $chainSystem];
        }

        // Legacy chatflows use LangChain {var} placeholders, which FleetQ does not interpolate.
        $texts = array_merge(array_column($messages, 'content'), [is_string($userMessage) ? $userMessage : '']);
        if (preg_match('/(?<!\{)\{\w+\}(?!\})/', implode("\n", $texts)) === 1) {
            $this->warnings[] = 'Prompts contain Flowise {variable} placeholders; they were copied unchanged and must be rewritten as FleetQ {{...}} context keys.';
        }

        $modelInputs = $modelNode !== null ? $this->inputs($modelNode) : [];
        $llmConfig = $this->llmConfig(
            $messages,
            $userMessage,
            $modelNode !== null ? $this->nodeName($modelNode) : null,
            is_string($modelInputs['modelName'] ?? null) ? $modelInputs['modelName'] : null,
            'LLM',
        );

        if ($skipped !== []) {
            $this->warnings[] = 'These Flowise components were not carried over: '.implode(', ', $skipped).'.';
        }

        $nodes = [
            ['id' => 'flowise_start', 'type' => 'start', 'label' => 'Start', 'position_x' => 0, 'position_y' => 0, 'config' => [], 'order' => 0],
            ['id' => 'flowise_llm', 'type' => 'llm', 'label' => $modelNode !== null ? $this->nodeLabel($modelNode, 'LLM') : 'LLM', 'position_x' => 300, 'position_y' => 0, 'config' => $llmConfig, 'order' => 1],
            ['id' => 'flowise_end', 'type' => 'end', 'label' => 'End', 'position_x' => 600, 'position_y' => 0, 'config' => [], 'order' => 2],
        ];

        $edges = [
            ['source_node_id' => 'flowise_start', 'target_node_id' => 'flowise_llm', 'label' => null, 'is_default' => false, 'sort_order' => 0],
            ['source_node_id' => 'flowise_llm', 'target_node_id' => 'flowise_end', 'label' => null, 'is_default' => false, 'sort_order' => 0],
        ];

        return [$nodes, $edges];
    }

    /**
     * @return array<string, mixed>
     */
    private function llmConfig(mixed $messages, mixed $userMessage, mixed $provider, ?string $modelName, string $label): array
    {
        $system = [];
        $other = [];
        foreach (is_array($messages) ? $messages : [] as $message) {
            if (! is_array($message) || ! is_string($message['content'] ?? null)) {
                continue;
            }
            if (($message['role'] ?? null) === 'system') {
                $system[] = $message['content'];
            } else {
                $other[] = $message['content'];
            }
        }

        $config = [];
        if ($system !== []) {
            $config['system_prompt'] = implode("\n\n", $system);
        }

        if (is_string($userMessage) && $userMessage !== '') {
            $config['prompt_template'] = $userMessage;
        } elseif ($other !== []) {
            $config['prompt_template'] = implode("\n\n", $other);
        } else {
            // Flowise implicitly sends the chat input here; FleetQ would send an empty user prompt.
            $this->warnings[] = "LLM '{$label}': no user message was found; set a prompt template in FleetQ.";
        }

        $providerName = is_string($provider) ? $provider : '';
        if (isset(self::PROVIDER_MAP[$providerName]) && $modelName !== null && $modelName !== '') {
            $config['model'] = self::PROVIDER_MAP[$providerName].'/'.$modelName;
        } else {
            $described = $providerName !== '' ? $providerName : 'unknown';
            $this->warnings[] = "LLM '{$label}': Flowise model '{$described}' could not be mapped; pick a model in FleetQ.";
        }

        return $config;
    }

    private function modelName(mixed $modelConfig): ?string
    {
        if (is_array($modelConfig) && is_string($modelConfig['modelName'] ?? null)) {
            return $modelConfig['modelName'];
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function headers(mixed $headers): array
    {
        $result = [];
        if (! is_array($headers)) {
            return $result;
        }

        foreach ($headers as $key => $value) {
            if (is_array($value) && is_scalar($value['key'] ?? null)) {
                $result[(string) $value['key']] = is_scalar($value['value'] ?? null) ? (string) $value['value'] : '';
            } elseif (is_string($key) && is_scalar($value)) {
                $result[$key] = (string) $value;
            }
        }

        return $result;
    }

    /**
     * Append Flowise query parameters to the URL. Values holding {{...}} placeholders are kept
     * verbatim so FleetQ can still interpolate them.
     *
     * @param  array<string, string>  $params
     */
    private function urlWithQuery(string $url, array $params): string
    {
        if ($params === []) {
            return $url;
        }

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = rawurlencode((string) $key).'='.(str_contains($value, '{{') ? $value : rawurlencode($value));
        }

        return $url.(str_contains($url, '?') ? '&' : '?').implode('&', $pairs);
    }

    /**
     * Whether any copied prompt/template text contains Flowise {{...}} placeholders.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    private function containsVariables(array $nodes): bool
    {
        foreach ($nodes as $node) {
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            foreach (self::TEXT_KEYS as $key) {
                if (is_string($config[$key] ?? null) && str_contains($config[$key], '{{')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function handleIndex(string $handle): int
    {
        if (preg_match('/(\d+)$/', $handle, $m) === 1) {
            return (int) $m[1];
        }

        return PHP_INT_MAX;
    }

    /**
     * @param  array<string, string>  $taken
     */
    private function uniqueId(string $base, array $taken): string
    {
        $id = $base;
        $i = 1;
        while (isset($taken[$id])) {
            $id = $base.'_'.$i++;
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $fNode
     */
    private function nodeName(array $fNode): string
    {
        $data = $fNode['data'] ?? null;

        return is_array($data) && is_string($data['name'] ?? null) ? $data['name'] : '';
    }

    /**
     * @param  array<string, mixed>  $fNode
     */
    private function nodeLabel(array $fNode, string $fallback): string
    {
        $data = $fNode['data'] ?? null;
        $label = is_array($data) ? ($data['label'] ?? null) : null;

        return is_string($label) && $label !== '' ? $label : $fallback;
    }

    /**
     * @param  array<string, mixed>  $fNode
     */
    private function nodeId(array $fNode, int $index): string
    {
        $id = $fNode['id'] ?? null;

        return is_scalar($id) && (string) $id !== '' ? (string) $id : 'flowise_node_'.$index;
    }

    /**
     * @param  array<string, mixed>  $fNode
     * @return array<mixed>
     */
    private function inputs(array $fNode): array
    {
        $data = $fNode['data'] ?? null;
        $inputs = is_array($data) ? ($data['inputs'] ?? null) : null;

        return is_array($inputs) ? $inputs : [];
    }

    /**
     * @param  array<string, mixed>  $fNode
     */
    private function coordinate(array $fNode, string $axis): int
    {
        $position = $fNode['position'] ?? null;
        $value = is_array($position) ? ($position[$axis] ?? 0) : 0;

        return is_numeric($value) ? (int) round((float) $value) : 0;
    }
}
