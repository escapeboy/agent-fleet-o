<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\Enums\AgentEnvironment;
use PHPUnit\Framework\TestCase;

class AgentEnvironmentTest extends TestCase
{
    public function test_minimal_environment_has_no_tool_slugs(): void
    {
        $this->assertSame([], AgentEnvironment::Minimal->toolSlugs());
    }

    public function test_coding_environment_maps_to_bash_and_filesystem(): void
    {
        $slugs = AgentEnvironment::Coding->toolSlugs();

        $this->assertContains('bash', $slugs);
        $this->assertContains('filesystem', $slugs);
    }

    public function test_browsing_environment_maps_to_browser_and_web_search(): void
    {
        $slugs = AgentEnvironment::Browsing->toolSlugs();

        $this->assertContains('browser', $slugs);
        $this->assertContains('web_search', $slugs);
    }

    public function test_restricted_environment_has_safe_tag_prefixes(): void
    {
        $prefixes = AgentEnvironment::Restricted->safeTagPrefixes();

        $this->assertNotEmpty($prefixes);
        $this->assertContains('get_', $prefixes);
        $this->assertContains('list_', $prefixes);
    }

    public function test_non_restricted_environments_have_no_safe_tag_prefixes(): void
    {
        $this->assertSame([], AgentEnvironment::Minimal->safeTagPrefixes());
        $this->assertSame([], AgentEnvironment::Coding->safeTagPrefixes());
        $this->assertSame([], AgentEnvironment::Browsing->safeTagPrefixes());
    }

    public function test_all_environments_have_label_and_description(): void
    {
        foreach (AgentEnvironment::cases() as $env) {
            $this->assertNotSame('', $env->label());
            $this->assertNotSame('', $env->description());
        }
    }
}
