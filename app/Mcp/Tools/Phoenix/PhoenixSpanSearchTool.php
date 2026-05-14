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
 * MCP tool: search spans in a Phoenix project by name pattern.
 *
 * Phoenix supports a rich filter DSL (`attributes['llm.model_name'] = 'X'`);
 * v1 of this tool exposes the simplest useful subset — span-name prefix
 * matching plus optional time window. Returns at most `limit` spans.
 */
#[IsReadOnly]
#[AssistantTool('read')]
class PhoenixSpanSearchTool extends Tool
{
    protected string $name = 'phoenix_span_search';

    protected string $description = 'Search Phoenix spans by name prefix in a project. '
        .'Useful for "show me all `memory.success_pattern` calls in the last hour" type queries.';

    public function __construct(private readonly PhoenixApiClient $client) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('Phoenix project global ID')
                ->required(),
            'name_prefix' => $schema->string()
                ->description('Span-name prefix to match. Empty = all spans.')
                ->default(''),
            'limit' => $schema->integer()
                ->description('Max spans to return. Default 50, max 200.')
                ->default(50),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project_id' => 'required|string',
            'name_prefix' => 'nullable|string|max:200',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        if (! $this->client->isConfigured()) {
            return Response::text(json_encode(['configured' => false, 'spans' => []]));
        }

        $filter = $validated['name_prefix']
            ? sprintf("name LIKE '%s%%'", addslashes($validated['name_prefix']))
            : null;

        $data = $this->client->query(<<<'GQL'
            query ($projectId: GlobalID!, $first: Int!, $filterCondition: String) {
                node(id: $projectId) {
                    ... on Project {
                        spans(
                            first: $first,
                            sort: { col: startTime, dir: desc },
                            filterCondition: $filterCondition
                        ) {
                            edges {
                                node {
                                    spanId
                                    traceId
                                    name
                                    startTime
                                    endTime
                                    tokenCountPrompt
                                    tokenCountCompletion
                                    statusCode
                                }
                            }
                        }
                    }
                }
            }
        GQL, [
            'projectId' => $validated['project_id'],
            'first' => $validated['limit'] ?? 50,
            'filterCondition' => $filter,
        ]);

        $spans = [];
        foreach ($data['node']['spans']['edges'] ?? [] as $edge) {
            $node = $edge['node'] ?? [];
            $spans[] = [
                'span_id' => $node['spanId'] ?? null,
                'trace_id' => $node['traceId'] ?? null,
                'name' => $node['name'] ?? null,
                'start_time' => $node['startTime'] ?? null,
                'end_time' => $node['endTime'] ?? null,
                'token_count_prompt' => $node['tokenCountPrompt'] ?? null,
                'token_count_completion' => $node['tokenCountCompletion'] ?? null,
                'status' => $node['statusCode'] ?? null,
            ];
        }

        return Response::text(json_encode([
            'configured' => true,
            'spans' => $spans,
            'total' => count($spans),
        ]));
    }
}
