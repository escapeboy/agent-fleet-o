<?php

namespace App\Mcp\Tools\Agent;

use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class AgentTemplatesListTool extends Tool
{
    protected string $name = 'agent_templates_list';

    protected string $description = 'List available agent templates. Returns slug, name, category, role, goal, capabilities, and skills for each template.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()
                ->description('Filter by category: engineering, content, business, design, research'),
        ];
    }

    public function handle(Request $request): Response
    {
        $templates = collect(config('agent-templates', []));

        if ($category = $request->get('category')) {
            $templates = $templates->where('category', $category);
        }

        return Response::text(json_encode([
            'count' => $templates->count(),
            'templates' => $templates->map(fn (array $t) => [
                'slug' => $t['slug'],
                'name' => $t['name'],
                'category' => $t['category'] ?? null,
                'role' => $t['role'],
                'goal' => $t['goal'],
                'capabilities' => $t['capabilities'] ?? [],
                'skills' => $t['skills'] ?? [],
            ])->values()->toArray(),
        ]));
    }
}
