<?php

namespace Tests\Unit\Config;

use Database\Seeders\SkillAndAgentSeeder;
use ReflectionMethod;
use Tests\TestCase;

class AgentTemplatesTest extends TestCase
{
    private const BUSINESS_TEAM_SLUGS = [
        'support-triage-agent',
        'support-reply-drafter',
        'lead-qualifier',
        'outbound-email-drafter',
        'meeting-notes-to-crm',
        'invoice-reviewer',
    ];

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
        $validCategories = ['engineering', 'content', 'business', 'design', 'research', 'support'];

        foreach (config('agent-templates') as $template) {
            $this->assertContains(
                $template['category'],
                $validCategories,
                "Template '{$template['name']}' has invalid category '{$template['category']}'",
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
        $this->assertCount(20, config('agent-templates'));
    }

    public function test_business_team_templates_exist(): void
    {
        $templates = collect(config('agent-templates'))->keyBy('slug');

        foreach (self::BUSINESS_TEAM_SLUGS as $slug) {
            $this->assertTrue($templates->has($slug), "Template '{$slug}' is missing");
        }

        $this->assertSame('support', $templates['support-triage-agent']['category']);
        $this->assertSame('support', $templates['support-reply-drafter']['category']);
    }

    public function test_template_skills_exist_in_skill_catalog(): void
    {
        $method = new ReflectionMethod(SkillAndAgentSeeder::class, 'skillDefinitions');
        $method->setAccessible(true);

        /** @var array<int, array{slug: string}> $definitions */
        $definitions = $method->invoke(new SkillAndAgentSeeder);
        $catalog = array_column($definitions, 'slug');

        foreach (config('agent-templates') as $template) {
            foreach ($template['skills'] as $skill) {
                $this->assertContains(
                    $skill,
                    $catalog,
                    "Template '{$template['slug']}' uses unknown skill '{$skill}'",
                );
            }
        }
    }

    public function test_business_team_templates_state_approval_and_budget(): void
    {
        $templates = collect(config('agent-templates'))->keyBy('slug');

        foreach (self::BUSINESS_TEAM_SLUGS as $slug) {
            $this->assertTrue($templates->has($slug), "Template '{$slug}' is missing");
            $text = strtolower($templates[$slug]['goal'].' '.$templates[$slug]['backstory']);

            $this->assertStringContainsString('approval', $text, "Template '{$slug}' does not mention approval");
            $this->assertStringContainsString('budget', $text, "Template '{$slug}' does not mention a budget");
        }
    }
}
