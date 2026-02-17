<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

class AgentTemplatesTest extends TestCase
{
    public function test_templates_config_exists_and_is_not_empty(): void
    {
        $templates = config('agent-templates');

        $this->assertIsArray($templates);
        $this->assertNotEmpty($templates);
    }

    public function test_each_template_has_required_fields(): void
    {
        $required = ['name', 'slug', 'category', 'role', 'goal', 'backstory', 'provider', 'model'];

        foreach (config('agent-templates') as $index => $template) {
            foreach ($required as $field) {
                $this->assertArrayHasKey($field, $template, "Template #{$index} missing '{$field}'");
                $this->assertNotEmpty($template[$field], "Template #{$index} has empty '{$field}'");
            }
        }
    }

    public function test_all_slugs_are_unique(): void
    {
        $slugs = collect(config('agent-templates'))->pluck('slug');

        $this->assertEquals($slugs->count(), $slugs->unique()->count(), 'Duplicate slugs found');
    }

    public function test_templates_have_valid_categories(): void
    {
        $validCategories = ['engineering', 'content', 'business', 'design', 'research'];

        foreach (config('agent-templates') as $template) {
            $this->assertContains(
                $template['category'],
                $validCategories,
                "Template '{$template['name']}' has invalid category '{$template['category']}'"
            );
        }
    }

    public function test_templates_have_capabilities(): void
    {
        foreach (config('agent-templates') as $template) {
            $this->assertArrayHasKey('capabilities', $template);
            $this->assertIsArray($template['capabilities']);
            $this->assertNotEmpty($template['capabilities'], "Template '{$template['name']}' has no capabilities");
        }
    }

    public function test_templates_have_skills(): void
    {
        foreach (config('agent-templates') as $template) {
            $this->assertArrayHasKey('skills', $template);
            $this->assertIsArray($template['skills']);
            $this->assertNotEmpty($template['skills'], "Template '{$template['name']}' has no skills");
        }
    }

    public function test_templates_have_personality(): void
    {
        foreach (config('agent-templates') as $template) {
            $this->assertArrayHasKey('personality', $template);
            $this->assertIsArray($template['personality']);
        }
    }

    public function test_contains_expected_template_count(): void
    {
        $this->assertCount(14, config('agent-templates'));
    }
}
