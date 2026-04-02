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
class A2uiValidateSurfaceTool extends Tool
{
    protected string $name = 'a2ui_validate_surface';

    protected string $description = 'Validate an A2UI component list against the v0.8 standard catalog. Returns validation result with any errors.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'components' => $schema->array()
                ->description('A2UI component array to validate')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $components = $request->get('components', []);

        $renderer = app(A2uiRenderer::class);
        $result = $renderer->validate($components);

        return Response::text(json_encode([
            'valid' => $result['valid'],
            'errors' => $result['errors'],
            'component_count' => count($components),
            'supported_types' => [
                'Text', 'Button', 'Card', 'Row', 'Column', 'List', 'Image', 'Icon',
                'Badge', 'Progress', 'Alert', 'Stat', 'Divider', 'Avatar', 'Rating',
                'Tabs', 'Modal', 'TextField', 'CheckBox', 'ChoicePicker', 'Slider',
                'DateTimeInput', 'Video', 'AudioPlayer',
            ],
        ], JSON_PRETTY_PRINT));
    }
}
