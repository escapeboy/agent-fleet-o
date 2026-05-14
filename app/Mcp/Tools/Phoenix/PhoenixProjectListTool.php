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
 * MCP tool: list Phoenix projects (tracing namespaces).
 *
 * Phoenix groups traces by project. The default project is `default`. Custom
 * projects can be created via the UI / SDK. This tool gives agents a way to
 * discover what's available before drilling into traces.
 */
#[IsReadOnly]
#[AssistantTool('read')]
class PhoenixProjectListTool extends Tool
{
    protected string $name = 'phoenix_project_list';

    protected string $description = 'List Phoenix LLM-tracing projects with span counts. '
        .'Returns an empty list when the Phoenix sidecar is not configured.';

    public function __construct(private readonly PhoenixApiClient $client) {}

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $request->validate([]);

        if (! $this->client->isConfigured()) {
            return Response::text(json_encode([
                'configured' => false,
                'projects' => [],
            ]));
        }

        $data = $this->client->query(<<<'GQL'
            query {
                projects(first: 50) {
                    edges {
                        node {
                            id
                            name
                            spanCountSummary: spanLatencyMsQuantiles(probabilities: [0.5]) { __typename }
                        }
                    }
                }
            }
        GQL);

        $projects = [];

        if (is_array($data) && isset($data['projects']['edges'])) {
            foreach ($data['projects']['edges'] as $edge) {
                $node = $edge['node'] ?? [];
                $projects[] = [
                    'id' => $node['id'] ?? null,
                    'name' => $node['name'] ?? null,
                ];
            }
        }

        return Response::text(json_encode([
            'configured' => true,
            'projects' => $projects,
            'total' => count($projects),
        ]));
    }
}
