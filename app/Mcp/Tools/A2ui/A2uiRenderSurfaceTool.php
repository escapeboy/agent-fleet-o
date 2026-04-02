<?php

namespace App\Mcp\Tools\A2ui;

use App\Infrastructure\A2ui\A2uiRenderer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class A2uiRenderSurfaceTool extends Tool
{
    protected string $name = 'a2ui_render_surface';

    protected string $description = 'Render an A2UI surface (flat adjacency list of components) to HTML. Returns the rendered HTML string. Use this to preview how an A2UI surface will look.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'components' => $schema->array()
                ->description('A2UI component array in adjacency list format. Each item has {id, component: {Type: {props}}}')
                ->required(),
            'data_model' => $schema->object()
                ->description('Optional data model for JSON Pointer binding'),
        ];
    }

    public function handle(Request $request): Response
    {
        $components = $request->get('components', []);
        $dataModel = $request->get('data_model', []);

        $renderer = app(A2uiRenderer::class);
        $validation = $renderer->validate($components);

        if (! $validation['valid']) {
            return Response::text(json_encode([
                'success' => false,
                'errors' => $validation['errors'],
            ], JSON_PRETTY_PRINT));
        }

        $html = $renderer->render($components, $dataModel)->toHtml();

        return Response::text(json_encode([
            'success' => true,
            'html' => $html,
            'component_count' => count($components),
        ], JSON_PRETTY_PRINT));
    }
}
