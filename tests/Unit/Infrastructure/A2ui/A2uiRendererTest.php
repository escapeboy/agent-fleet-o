<?php

namespace Tests\Unit\Infrastructure\A2ui;

use App\Infrastructure\A2ui\A2uiRenderer;
use PHPUnit\Framework\TestCase;

class A2uiRendererTest extends TestCase
{
    private A2uiRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new A2uiRenderer;
    }

    public function test_renders_empty_components(): void
    {
        $html = $this->renderer->render([]);
        $this->assertSame('', $html->toHtml());
    }

    public function test_renders_text_component(): void
    {
        $components = [
            ['id' => 'title', 'component' => ['Text' => ['text' => 'Hello World', 'variant' => 'h2']]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('Hello World', $html);
        $this->assertStringContainsString('<h2', $html);
        $this->assertStringContainsString('a2ui-surface', $html);
    }

    public function test_renders_card_with_children(): void
    {
        $components = [
            ['id' => 'card', 'component' => ['Card' => ['child' => 'title']]],
            ['id' => 'title', 'component' => ['Text' => ['text' => 'Card Title', 'variant' => 'h3']]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('Card Title', $html);
        $this->assertStringContainsString('rounded-xl', $html);
    }

    public function test_renders_row_column_layout(): void
    {
        $components = [
            ['id' => 'row', 'component' => ['Row' => ['children' => ['explicitList' => ['a', 'b']]]]],
            ['id' => 'a', 'component' => ['Text' => ['text' => 'Left']]],
            ['id' => 'b', 'component' => ['Text' => ['text' => 'Right']]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('flex flex-row', $html);
        $this->assertStringContainsString('Left', $html);
        $this->assertStringContainsString('Right', $html);
    }

    public function test_renders_stat_component(): void
    {
        $components = [
            ['id' => 'stat', 'component' => ['Stat' => ['label' => 'Revenue', 'value' => '$12,345', 'change' => '+15%', 'trend' => 'up']]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('Revenue', $html);
        $this->assertStringContainsString('$12,345', $html);
        $this->assertStringContainsString('+15%', $html);
        $this->assertStringContainsString('text-green-600', $html);
    }

    public function test_renders_badge(): void
    {
        $components = [
            ['id' => 'badge', 'component' => ['Badge' => ['text' => 'Active', 'variant' => 'success']]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('Active', $html);
        $this->assertStringContainsString('bg-green-100', $html);
    }

    public function test_renders_progress_bar(): void
    {
        $components = [
            ['id' => 'prog', 'component' => ['Progress' => ['value' => 75, 'label' => 'Completion']]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('75%', $html);
        $this->assertStringContainsString('Completion', $html);
        $this->assertStringContainsString('width: 75%', $html);
    }

    public function test_renders_alert(): void
    {
        $components = [
            ['id' => 'alert', 'component' => ['Alert' => ['message' => 'Task done', 'title' => 'Success', 'variant' => 'success']]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('Task done', $html);
        $this->assertStringContainsString('Success', $html);
        $this->assertStringContainsString('bg-green-50', $html);
    }

    public function test_renders_button_variants(): void
    {
        $components = [
            ['id' => 'btn', 'component' => ['Button' => ['label' => 'Submit', 'variant' => 'primary']]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('Submit', $html);
        $this->assertStringContainsString('<button', $html);
        $this->assertStringContainsString('bg-primary-600', $html);
    }

    public function test_renders_text_field(): void
    {
        $components = [
            ['id' => 'field', 'component' => ['TextField' => ['label' => 'Email', 'placeholder' => 'you@example.com', 'type' => 'email']]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('Email', $html);
        $this->assertStringContainsString('type="email"', $html);
        $this->assertStringContainsString('you@example.com', $html);
    }

    public function test_renders_checkbox(): void
    {
        $components = [
            ['id' => 'check', 'component' => ['CheckBox' => ['label' => 'Accept terms', 'checked' => true]]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('Accept terms', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('checked', $html);
    }

    public function test_renders_divider(): void
    {
        $components = [
            ['id' => 'div', 'component' => ['Divider' => []]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('<hr', $html);
    }

    public function test_renders_avatar_with_initials(): void
    {
        $components = [
            ['id' => 'av', 'component' => ['Avatar' => ['name' => 'John Doe', 'size' => 'lg']]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('JD', $html);
        $this->assertStringContainsString('rounded-full', $html);
    }

    public function test_renders_rating(): void
    {
        $components = [
            ['id' => 'rate', 'component' => ['Rating' => ['value' => 3]]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('text-yellow-400', $html);
        // Should have 5 stars total
        $this->assertSame(5, substr_count($html, '<svg'));
    }

    public function test_data_binding_resolves_json_pointers(): void
    {
        $components = [
            ['id' => 'text', 'component' => ['Text' => ['text' => ['path' => '/user/name']]]],
        ];

        $dataModel = ['user' => ['name' => 'Alice']];

        $html = $this->renderer->render($components, $dataModel)->toHtml();

        $this->assertStringContainsString('Alice', $html);
    }

    public function test_validates_valid_surface(): void
    {
        $components = [
            ['id' => 'root', 'component' => ['Card' => ['child' => 'text']]],
            ['id' => 'text', 'component' => ['Text' => ['text' => 'Hello']]],
        ];

        $result = $this->renderer->validate($components);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_validates_missing_id(): void
    {
        $components = [
            ['component' => ['Text' => ['text' => 'Hello']]],
        ];

        $result = $this->renderer->validate($components);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString("missing 'id'", $result['errors'][0]);
    }

    public function test_validates_missing_child_reference(): void
    {
        $components = [
            ['id' => 'card', 'component' => ['Card' => ['child' => 'nonexistent']]],
        ];

        $result = $this->renderer->validate($components);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString("missing child 'nonexistent'", $result['errors'][0]);
    }

    public function test_validates_unsupported_type(): void
    {
        $components = [
            ['id' => 'custom', 'component' => ['CustomWidget' => []]],
        ];

        $result = $this->renderer->validate($components);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('unsupported type', $result['errors'][0]);
    }

    public function test_sanitizes_javascript_urls(): void
    {
        $components = [
            ['id' => 'img', 'component' => ['Image' => ['src' => 'javascript:alert(1)']]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('Image missing src', $html);
    }

    public function test_escapes_html_in_text(): void
    {
        $components = [
            ['id' => 'text', 'component' => ['Text' => ['text' => '<script>alert(1)</script>']]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_renders_complex_surface(): void
    {
        $components = [
            ['id' => 'root', 'component' => ['Card' => ['child' => 'col']]],
            ['id' => 'col', 'component' => ['Column' => ['children' => ['explicitList' => ['title', 'stats', 'alert', 'btn']]]]],
            ['id' => 'title', 'component' => ['Text' => ['text' => 'Dashboard', 'variant' => 'h2']]],
            ['id' => 'stats', 'component' => ['Row' => ['children' => ['explicitList' => ['stat1', 'stat2']]]]],
            ['id' => 'stat1', 'component' => ['Stat' => ['label' => 'Users', 'value' => '1,234']]],
            ['id' => 'stat2', 'component' => ['Stat' => ['label' => 'Revenue', 'value' => '$56K']]],
            ['id' => 'alert', 'component' => ['Alert' => ['message' => 'All systems operational', 'variant' => 'success']]],
            ['id' => 'btn', 'component' => ['Button' => ['label' => 'View Details', 'variant' => 'primary']]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('Dashboard', $html);
        $this->assertStringContainsString('1,234', $html);
        $this->assertStringContainsString('$56K', $html);
        $this->assertStringContainsString('All systems operational', $html);
        $this->assertStringContainsString('View Details', $html);
    }

    public function test_unknown_component_renders_comment(): void
    {
        $components = [
            ['id' => 'x', 'component' => ['FancyWidget' => ['data' => 'test']]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('<!-- A2UI: unsupported component', $html);
    }

    public function test_renders_choice_picker(): void
    {
        $components = [
            ['id' => 'pick', 'component' => ['ChoicePicker' => [
                'label' => 'Priority',
                'options' => [
                    ['value' => 'low', 'label' => 'Low'],
                    ['value' => 'high', 'label' => 'High'],
                ],
                'value' => 'high',
            ]]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('Priority', $html);
        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('selected', $html);
    }

    public function test_renders_slider(): void
    {
        $components = [
            ['id' => 'sl', 'component' => ['Slider' => ['label' => 'Volume', 'min' => 0, 'max' => 100, 'value' => 70]]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('Volume', $html);
        $this->assertStringContainsString('type="range"', $html);
        $this->assertStringContainsString('value="70"', $html);
    }

    public function test_renders_tabs_with_alpine(): void
    {
        $components = [
            ['id' => 'tabs', 'component' => ['Tabs' => ['tabs' => [
                ['label' => 'Overview', 'content' => 'tab1'],
                ['label' => 'Details', 'content' => 'tab2'],
            ]]]],
            ['id' => 'tab1', 'component' => ['Text' => ['text' => 'Overview content']]],
            ['id' => 'tab2', 'component' => ['Text' => ['text' => 'Details content']]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('Overview', $html);
        $this->assertStringContainsString('Details', $html);
        $this->assertStringContainsString('x-data', $html);
        $this->assertStringContainsString('activeTab', $html);
    }

    public function test_cycle_detection_prevents_infinite_recursion(): void
    {
        $components = [
            ['id' => 'a', 'component' => ['Card' => ['child' => 'b']]],
            ['id' => 'b', 'component' => ['Card' => ['child' => 'a']]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('cycle detected', $html);
    }

    public function test_recursive_data_binding(): void
    {
        $components = [
            ['id' => 'tabs', 'component' => ['Tabs' => ['tabs' => [
                ['label' => ['path' => '/tabs/0/label'], 'content' => 'text'],
            ]]]],
            ['id' => 'text', 'component' => ['Text' => ['text' => 'Content']]],
        ];

        $dataModel = ['tabs' => [['label' => 'Dynamic Tab']]];

        $html = $this->renderer->render($components, $dataModel)->toHtml();

        $this->assertStringContainsString('Dynamic Tab', $html);
    }

    public function test_blocks_file_uri(): void
    {
        $components = [
            ['id' => 'img', 'component' => ['Image' => ['src' => 'file:///etc/passwd']]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringNotContainsString('file:', $html);
        $this->assertStringContainsString('Image missing src', $html);
    }

    public function test_component_limit_exceeded(): void
    {
        $components = [];
        for ($i = 0; $i < 501; $i++) {
            $components[] = ['id' => "c{$i}", 'component' => ['Text' => ['text' => "Item {$i}"]]];
        }

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringContainsString('component limit exceeded', $html);
    }

    public function test_blocks_data_uri(): void
    {
        $components = [
            ['id' => 'img', 'component' => ['Image' => ['src' => 'data:text/html,<script>alert(1)</script>']]],
        ];

        $html = $this->renderer->render($components)->toHtml();

        $this->assertStringNotContainsString('data:', $html);
    }
}
