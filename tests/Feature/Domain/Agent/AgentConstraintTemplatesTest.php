<?php

namespace Tests\Feature\Domain\Agent;

use App\Mcp\Tools\Agent\AgentConstraintTemplatesTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

class AgentConstraintTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_tool_returns_all_templates(): void
    {
        $tool = new AgentConstraintTemplatesTool;
        $response = $tool->handle(new Request([]));
        $data = json_decode((string) $response->content(), true);

        $this->assertArrayHasKey('count', $data);
        $this->assertArrayHasKey('templates', $data);
        $this->assertGreaterThan(0, $data['count']);
        $this->assertCount($data['count'], $data['templates']);
    }

    public function test_each_template_has_required_fields(): void
    {
        $tool = new AgentConstraintTemplatesTool;
        $data = json_decode((string) $tool->handle(new Request([]))->content(), true);

        foreach ($data['templates'] as $template) {
            $this->assertArrayHasKey('slug', $template);
            $this->assertArrayHasKey('name', $template);
            $this->assertArrayHasKey('description', $template);
            $this->assertArrayHasKey('rules', $template);
            $this->assertNotEmpty($template['slug']);
            $this->assertNotEmpty($template['name']);
            $this->assertNotEmpty($template['description']);
            $this->assertIsArray($template['rules']);
            $this->assertNotEmpty($template['rules']);
        }
    }

    public function test_anti_sycophancy_template_is_present(): void
    {
        $tool = new AgentConstraintTemplatesTool;
        $data = json_decode((string) $tool->handle(new Request([]))->content(), true);

        $slugs = array_column($data['templates'], 'slug');
        $this->assertContains('anti-sycophancy', $slugs);
    }

    public function test_all_expected_templates_are_present(): void
    {
        $tool = new AgentConstraintTemplatesTool;
        $data = json_decode((string) $tool->handle(new Request([]))->content(), true);

        $slugs = array_column($data['templates'], 'slug');

        foreach (['anti-sycophancy', 'direct-communicator', 'uncertainty-first', 'evidence-based', 'completeness-principle'] as $slug) {
            $this->assertContains($slug, $slugs, "Expected template '{$slug}' not found.");
        }
    }

    public function test_each_template_has_at_least_one_rule(): void
    {
        $tool = new AgentConstraintTemplatesTool;
        $data = json_decode((string) $tool->handle(new Request([]))->content(), true);

        foreach ($data['templates'] as $template) {
            $this->assertGreaterThanOrEqual(1, count($template['rules']), "Template '{$template['slug']}' has no rules.");
        }
    }

    public function test_tool_requires_no_input(): void
    {
        // Tool schema should accept empty input
        $tool = new AgentConstraintTemplatesTool;
        $response = $tool->handle(new Request([]));

        $this->assertNotNull($response);
        $data = json_decode((string) $response->content(), true);
        $this->assertIsArray($data);
    }
}
