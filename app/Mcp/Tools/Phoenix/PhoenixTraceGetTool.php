<?php

namespace App\Mcp\Tools\Phoenix;

use App\Infrastructure\AI\Services\PhoenixApiClient;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * MCP tool: fetch one trace with all its spans by Phoenix trace_id.
 *
 * Use to drill into a specific LLM call sequence — see prompts, tool calls,
 * outputs, token counts. Especially useful for debugging "why did the agent
 * answer X" sort of questions.
 */
#[IsReadOnly]
#[AssistantTool('read')]
class PhoenixTraceGetTool extends Tool
{
    protected string $name = 'phoenix_trace_get';

    protected string $description = 'Get one Phoenix trace by trace_id, with its full span tree (prompts, responses, '
        .'tokens, tool calls, metadata). Returns notFound when the trace is missing.';

    public function __construct(private readonly PhoenixApiClient $client) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('Phoenix project global ID')
                ->required(),
            'trace_id' => $schema->string()
                ->description('32-hex OTel trace identifier')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project_id' => 'required|string',
            'trace_id' => 'required|string|max:64',
        ]);

        if (! $this->client->isConfigured()) {
            return Response::text(json_encode(['configured' => false, 'spans' => []]));
        }

        $data = $this->client->query(<<<'GQL'
            query ($projectId: GlobalID!, $traceId: ID!) {
                node(id: $projectId) {
                    ... on Project {
                        trace(traceId: $traceId) {
                            traceId
                            spans(first: 200) {
                                edges {
                                    node {
                                        spanId
                                        parentId
                                        name
                                        spanKind
                                        startTime
                                        endTime
                                        statusCode
                                        statusMessage
                                        tokenCountPrompt
                                        tokenCountCompletion
                                        attributes
                                    }
                                }
                            }
                        }
                    }
                }
            }
        GQL, [
            'projectId' => $validated['project_id'],
            'traceId' => $validated['trace_id'],
        ]);

        $trace = $data['node']['trace'] ?? null;
        if ($trace === null) {
            return $this->notFoundError("Trace {$validated['trace_id']} not found in project {$validated['project_id']}");
        }

        $spans = [];
        foreach ($trace['spans']['edges'] ?? [] as $edge) {
            $node = $edge['node'] ?? [];
            $attributes = $node['attributes'] ?? null;
            if (is_string($attributes)) {
                $decoded = json_decode($attributes, true);
                $attributes = is_array($decoded) ? $decoded : $attributes;
            }
            $spans[] = [
                'span_id' => $node['spanId'] ?? null,
                'parent_id' => $node['parentId'] ?? null,
                'name' => $node['name'] ?? null,
                'kind' => $node['spanKind'] ?? null,
                'start_time' => $node['startTime'] ?? null,
                'end_time' => $node['endTime'] ?? null,
                'status' => $node['statusCode'] ?? null,
                'status_message' => $node['statusMessage'] ?? null,
                'token_count_prompt' => $node['tokenCountPrompt'] ?? null,
                'token_count_completion' => $node['tokenCountCompletion'] ?? null,
                'attributes' => $attributes,
            ];
        }

        return Response::text(json_encode([
            'configured' => true,
            'trace_id' => $trace['traceId'] ?? $validated['trace_id'],
            'spans' => $spans,
            'span_count' => count($spans),
        ]));
    }
}
