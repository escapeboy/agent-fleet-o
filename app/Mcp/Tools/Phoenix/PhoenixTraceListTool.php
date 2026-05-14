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
 * MCP tool: list recent traces in a Phoenix project.
 */
#[IsReadOnly]
#[AssistantTool('read')]
class PhoenixTraceListTool extends Tool
{
    protected string $name = 'phoenix_trace_list';

    protected string $description = 'List recent Phoenix traces in a project, newest first. '
        .'Useful to surface what LLM activity happened recently.';

    public function __construct(private readonly PhoenixApiClient $client) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('Phoenix project global ID (from phoenix_project_list)')
                ->required(),
            'limit' => $schema->integer()
                ->description('Max traces to return. Default 20, max 100.')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project_id' => 'required|string',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if (! $this->client->isConfigured()) {
            return Response::text(json_encode(['configured' => false, 'traces' => []]));
        }

        $data = $this->client->query(<<<'GQL'
            query ($id: GlobalID!, $first: Int!) {
                node(id: $id) {
                    ... on Project {
                        traces(first: $first, sort: { col: startTime, dir: desc }) {
                            edges {
                                node {
                                    traceId
                                    rootSpan {
                                        name
                                        startTime
                                        endTime
                                        statusCode
                                    }
                                }
                            }
                        }
                    }
                }
            }
        GQL, [
            'id' => $validated['project_id'],
            'first' => $validated['limit'] ?? 20,
        ]);

        $traces = [];
        $edges = $data['node']['traces']['edges'] ?? [];
        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];
            $traces[] = [
                'trace_id' => $node['traceId'] ?? null,
                'root_name' => $node['rootSpan']['name'] ?? null,
                'start_time' => $node['rootSpan']['startTime'] ?? null,
                'end_time' => $node['rootSpan']['endTime'] ?? null,
                'status' => $node['rootSpan']['statusCode'] ?? null,
            ];
        }

        return Response::text(json_encode([
            'configured' => true,
            'traces' => $traces,
            'total' => count($traces),
        ]));
    }
}
