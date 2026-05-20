<?php

namespace Tests\Feature\View\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class AskAiTest extends TestCase
{
    public function test_renders_with_context_only(): void
    {
        $html = Blade::render('<x-ask-ai context="experiment-detail" />');

        $this->assertStringContainsString('data-ask-ai-context="experiment-detail"', $html);
        $this->assertStringNotContainsString('data-ask-ai-context-id', $html);
        $this->assertStringContainsString('Ask AI', $html);
        $this->assertStringContainsString('assistant-open-with-context', $html);
        $this->assertStringContainsString('open-assistant', $html);
    }

    public function test_renders_with_context_id_and_custom_label(): void
    {
        $html = Blade::render(
            '<x-ask-ai context="skill-detail" :context-id="$id" label="Ask AI about this skill" />',
            ['id' => 'skill-uuid-123'],
        );

        $this->assertStringContainsString('data-ask-ai-context="skill-detail"', $html);
        $this->assertStringContainsString('data-ask-ai-context-id="skill-uuid-123"', $html);
        $this->assertStringContainsString('Ask AI about this skill', $html);
        $this->assertStringContainsString('skill-uuid-123', $html);
    }

    public function test_size_variants_apply_padding_classes(): void
    {
        $sm = Blade::render('<x-ask-ai context="workflow-builder" size="sm" />');
        $md = Blade::render('<x-ask-ai context="workflow-builder" size="md" />');
        $xs = Blade::render('<x-ask-ai context="workflow-builder" size="xs" />');

        $this->assertStringContainsString('px-2.5 py-1.5 text-xs', $sm);
        $this->assertStringContainsString('px-3 py-2 text-sm', $md);
        $this->assertStringContainsString('px-2 py-1 text-xs', $xs);
    }
}
