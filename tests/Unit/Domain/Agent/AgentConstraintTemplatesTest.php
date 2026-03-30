<?php

namespace Tests\Unit\Domain\Agent;

use App\Mcp\Tools\Agent\AgentConstraintTemplatesTool;
use Laravel\Mcp\Request;
use Tests\TestCase;

class AgentConstraintTemplatesTest extends TestCase
{
    public function test_config_returns_five_templates(): void
    {
        $templates = config('agent-constraint-templates', []);

        $this->assertCount(5, $templates);
    }

    public function test_each_template_has_required_fields(): void
    {
        $templates = config('agent-constraint-templates', []);

        foreach ($templates as $template) {
            $this->assertArrayHasKey('slug', $template, 'Template missing slug');
            $this->assertArrayHasKey('name', $template, 'Template missing name');
            $this->assertArrayHasKey('description', $template, 'Template missing description');
            $this->assertArrayHasKey('rules', $template, 'Template missing rules');

            $this->assertIsString($template['slug']);
            $this->assertNotEmpty($template['slug']);

            $this->assertIsString($template['name']);
            $this->assertNotEmpty($template['name']);

            $this->assertIsString($template['description']);
            $this->assertNotEmpty($template['description']);

            $this->assertIsArray($template['rules']);
            $this->assertNotEmpty($template['rules']);
        }
    }

    public function test_template_slugs_are_unique(): void
    {
        $templates = config('agent-constraint-templates', []);
        $slugs = array_column($templates, 'slug');

        $this->assertCount(count($slugs), array_unique($slugs), 'Duplicate slugs found in constraint templates');
    }

    public function test_expected_slugs_are_present(): void
    {
        $templates = config('agent-constraint-templates', []);
        $slugs = array_column($templates, 'slug');

        $this->assertContains('anti-sycophancy', $slugs);
        $this->assertContains('direct-communicator', $slugs);
        $this->assertContains('uncertainty-first', $slugs);
        $this->assertContains('evidence-based', $slugs);
        $this->assertContains('completeness-principle', $slugs);
    }

    public function test_mcp_tool_returns_all_five_templates(): void
    {
        $tool = new AgentConstraintTemplatesTool;
        $request = new Request([]);

        $response = $tool->handle($request);
        $payload = json_decode((string) $response->content(), true);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('count', $payload);
        $this->assertArrayHasKey('templates', $payload);
        $this->assertSame(5, $payload['count']);
        $this->assertCount(5, $payload['templates']);
    }

    public function test_mcp_tool_response_includes_required_fields_per_template(): void
    {
        $tool = new AgentConstraintTemplatesTool;
        $request = new Request([]);

        $response = $tool->handle($request);
        $payload = json_decode((string) $response->content(), true);

        foreach ($payload['templates'] as $template) {
            $this->assertArrayHasKey('slug', $template);
            $this->assertArrayHasKey('name', $template);
            $this->assertArrayHasKey('description', $template);
            $this->assertArrayHasKey('rules', $template);
            $this->assertIsArray($template['rules']);
            $this->assertNotEmpty($template['rules']);
        }
    }
}
