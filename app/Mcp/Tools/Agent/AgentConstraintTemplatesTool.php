<?php

namespace App\Mcp\Tools\Agent;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class AgentConstraintTemplatesTool extends Tool
{
    protected string $name = 'agent_constraint_templates_list';

    protected string $description = 'List available behavioral constraint templates for agents. Returns slug, name, description, and rules array for each template. Templates encode quality defaults such as anti-sycophancy, directness, uncertainty surfacing, evidence requirements, and completeness.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $templates = config('agent-constraint-templates', []);

        return Response::text(json_encode([
            'count' => count($templates),
            'templates' => array_map(fn (array $t) => [
                'slug' => $t['slug'],
                'name' => $t['name'],
                'description' => $t['description'],
                'rules' => $t['rules'],
            ], $templates),
        ]));
    }
}
