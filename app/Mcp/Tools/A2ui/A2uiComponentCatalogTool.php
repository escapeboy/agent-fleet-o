<?php

namespace App\Mcp\Tools\A2ui;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class A2uiComponentCatalogTool extends Tool
{
    protected string $name = 'a2ui_component_catalog';

    protected string $description = 'Get the A2UI component catalog — lists all 24 supported component types with their properties and usage examples. Use this to learn how to construct A2UI surfaces.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'component_type' => $schema->string()
                ->description('Optional: get details for a specific component type (e.g. Card, Text, Alert)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $filter = $request->get('component_type');

        $catalog = [
            'Text' => [
                'description' => 'Display text content with various typographic styles',
                'props' => ['text' => 'string (required)', 'variant' => 'h1|h2|h3|h4|body|caption|label|code'],
                'example' => ['id' => 'title', 'component' => ['Text' => ['text' => 'Hello World', 'variant' => 'h2']]],
            ],
            'Button' => [
                'description' => 'Interactive button with variants',
                'props' => ['label' => 'string', 'variant' => 'primary|secondary|danger|ghost|link', 'disabled' => 'bool', 'action' => 'string'],
                'example' => ['id' => 'btn', 'component' => ['Button' => ['label' => 'Click me', 'variant' => 'primary']]],
            ],
            'Card' => [
                'description' => 'Container card with border and shadow',
                'props' => ['child' => 'string (child id)', 'children' => '{explicitList: [ids]}', 'padding' => 'int (px)'],
                'example' => ['id' => 'card', 'component' => ['Card' => ['child' => 'content']]],
            ],
            'Row' => [
                'description' => 'Horizontal flex container',
                'props' => ['children' => '{explicitList: [ids]}', 'gap' => 'int', 'align' => 'start|center|end|stretch', 'justify' => 'start|center|end|between|around'],
                'example' => ['id' => 'row', 'component' => ['Row' => ['children' => ['explicitList' => ['a', 'b']]]]],
            ],
            'Column' => [
                'description' => 'Vertical flex container',
                'props' => ['children' => '{explicitList: [ids]}', 'gap' => 'int'],
                'example' => ['id' => 'col', 'component' => ['Column' => ['children' => ['explicitList' => ['a', 'b']]]]],
            ],
            'List' => [
                'description' => 'List container with optional dividers',
                'props' => ['children' => '{explicitList: [ids]}', 'divider' => 'bool'],
                'example' => ['id' => 'list', 'component' => ['List' => ['children' => ['explicitList' => ['item1', 'item2']], 'divider' => true]]],
            ],
            'Image' => [
                'description' => 'Image display',
                'props' => ['src' => 'string (url)', 'alt' => 'string', 'width' => 'int', 'height' => 'int', 'fit' => 'cover|contain|fill|none', 'borderRadius' => 'full|lg|none'],
                'example' => ['id' => 'img', 'component' => ['Image' => ['src' => 'https://example.com/img.jpg', 'alt' => 'Example']]],
            ],
            'Icon' => [
                'description' => 'Icon display',
                'props' => ['name' => 'string', 'size' => 'xs|sm|md|lg|xl', 'color' => 'string (CSS color)'],
                'example' => ['id' => 'icon', 'component' => ['Icon' => ['name' => 'star', 'size' => 'lg']]],
            ],
            'Badge' => [
                'description' => 'Colored label badge',
                'props' => ['text' => 'string', 'variant' => 'default|success|warning|error|info|purple'],
                'example' => ['id' => 'badge', 'component' => ['Badge' => ['text' => 'Active', 'variant' => 'success']]],
            ],
            'Progress' => [
                'description' => 'Progress bar with percentage',
                'props' => ['value' => 'int (0-100)', 'label' => 'string', 'color' => 'primary|success|warning|error', 'showValue' => 'bool'],
                'example' => ['id' => 'prog', 'component' => ['Progress' => ['value' => 75, 'label' => 'Completion']]],
            ],
            'Alert' => [
                'description' => 'Alert notification box with icon',
                'props' => ['message' => 'string', 'title' => 'string', 'variant' => 'info|success|warning|error'],
                'example' => ['id' => 'alert', 'component' => ['Alert' => ['message' => 'Task completed', 'variant' => 'success']]],
            ],
            'Stat' => [
                'description' => 'Statistic card with label, value, and optional change',
                'props' => ['label' => 'string', 'value' => 'string|number', 'change' => 'string', 'trend' => 'up|down|neutral'],
                'example' => ['id' => 'stat', 'component' => ['Stat' => ['label' => 'Revenue', 'value' => '$12,345', 'change' => '+15%', 'trend' => 'up']]],
            ],
            'Divider' => [
                'description' => 'Horizontal divider line',
                'props' => [],
                'example' => ['id' => 'div', 'component' => ['Divider' => []]],
            ],
            'Avatar' => [
                'description' => 'User avatar (image or initials)',
                'props' => ['src' => 'string (url)', 'name' => 'string (for initials)', 'size' => 'xs|sm|md|lg|xl'],
                'example' => ['id' => 'av', 'component' => ['Avatar' => ['name' => 'John Doe', 'size' => 'md']]],
            ],
            'Rating' => [
                'description' => 'Star rating display',
                'props' => ['value' => 'number (0-5)', 'max' => 'int (default 5)'],
                'example' => ['id' => 'rate', 'component' => ['Rating' => ['value' => 4.5]]],
            ],
            'Tabs' => [
                'description' => 'Tabbed content container',
                'props' => ['tabs' => '[{label: string, content: string (child id)}]'],
                'example' => ['id' => 'tabs', 'component' => ['Tabs' => ['tabs' => [['label' => 'Tab 1', 'content' => 'panel1'], ['label' => 'Tab 2', 'content' => 'panel2']]]]],
            ],
            'Modal' => [
                'description' => 'Modal dialog overlay',
                'props' => ['title' => 'string', 'child' => 'string (child id)', 'children' => '{explicitList: [ids]}', 'open' => 'bool'],
                'example' => ['id' => 'modal', 'component' => ['Modal' => ['title' => 'Confirm', 'child' => 'modal_body']]],
            ],
            'TextField' => [
                'description' => 'Text input field with label',
                'props' => ['label' => 'string', 'placeholder' => 'string', 'value' => 'string', 'type' => 'text|email|password|number|tel|url', 'hint' => 'string', 'required' => 'bool', 'name' => 'string'],
                'example' => ['id' => 'field', 'component' => ['TextField' => ['label' => 'Email', 'type' => 'email', 'placeholder' => 'you@example.com']]],
            ],
            'CheckBox' => [
                'description' => 'Checkbox with label',
                'props' => ['label' => 'string', 'checked' => 'bool', 'name' => 'string'],
                'example' => ['id' => 'check', 'component' => ['CheckBox' => ['label' => 'Accept terms']]],
            ],
            'ChoicePicker' => [
                'description' => 'Dropdown select or radio group',
                'props' => ['label' => 'string', 'options' => '[string | {value, label}]', 'value' => 'string', 'name' => 'string'],
                'example' => ['id' => 'pick', 'component' => ['ChoicePicker' => ['label' => 'Priority', 'options' => ['Low', 'Medium', 'High'], 'value' => 'Medium']]],
            ],
            'Slider' => [
                'description' => 'Range slider input',
                'props' => ['label' => 'string', 'min' => 'int', 'max' => 'int', 'value' => 'int', 'step' => 'int', 'name' => 'string'],
                'example' => ['id' => 'sl', 'component' => ['Slider' => ['label' => 'Temperature', 'min' => 0, 'max' => 100, 'value' => 70]]],
            ],
            'DateTimeInput' => [
                'description' => 'Date/time input field',
                'props' => ['label' => 'string', 'value' => 'string', 'type' => 'date|time|datetime-local', 'name' => 'string'],
                'example' => ['id' => 'dt', 'component' => ['DateTimeInput' => ['label' => 'Due Date', 'type' => 'date']]],
            ],
            'Video' => [
                'description' => 'Video player',
                'props' => ['src' => 'string (url)', 'poster' => 'string (url)', 'autoplay' => 'bool', 'controls' => 'bool'],
                'example' => ['id' => 'vid', 'component' => ['Video' => ['src' => 'https://example.com/video.mp4']]],
            ],
            'AudioPlayer' => [
                'description' => 'Audio player',
                'props' => ['src' => 'string (url)'],
                'example' => ['id' => 'audio', 'component' => ['AudioPlayer' => ['src' => 'https://example.com/audio.mp3']]],
            ],
        ];

        if ($filter && isset($catalog[$filter])) {
            return Response::text(json_encode($catalog[$filter], JSON_PRETTY_PRINT));
        }

        if ($filter) {
            return Response::text(json_encode([
                'error' => "Unknown component type: {$filter}",
                'available_types' => array_keys($catalog),
            ], JSON_PRETTY_PRINT));
        }

        return Response::text(json_encode([
            'catalog_version' => 'v0.8',
            'component_count' => count($catalog),
            'components' => $catalog,
            'adjacency_list_example' => [
                ['id' => 'root', 'component' => ['Card' => ['child' => 'col']]],
                ['id' => 'col', 'component' => ['Column' => ['children' => ['explicitList' => ['title', 'body', 'btn']]]]],
                ['id' => 'title', 'component' => ['Text' => ['text' => 'Hello A2UI!', 'variant' => 'h2']]],
                ['id' => 'body', 'component' => ['Text' => ['text' => 'This is a card with a title and button.']]],
                ['id' => 'btn', 'component' => ['Button' => ['label' => 'Get Started', 'variant' => 'primary']]],
            ],
        ], JSON_PRETTY_PRINT));
    }
}
