<?php

namespace Tests\Unit\Domain\GitRepository;

use App\Domain\GitRepository\Services\ContextMarkdownRenderer;
use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Models\Memory;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class ContextMarkdownRendererTest extends TestCase
{
    private ContextMarkdownRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new ContextMarkdownRenderer;
    }

    public function test_renders_artifact_with_frontmatter_and_latest_version_body(): void
    {
        $artifact = new Artifact([
            'type' => 'landing_page',
            'name' => 'Spring Campaign',
            'current_version' => 2,
            'experiment_id' => 'exp-123',
        ]);
        $artifact->id = '019e0000-0000-7000-8000-0000000000ab';
        $artifact->setRelation('versions', collect([
            new ArtifactVersion(['version' => 1, 'content' => 'old draft']),
            new ArtifactVersion(['version' => 2, 'content' => 'Hello body']),
        ]));

        $result = $this->renderer->artifact($artifact, 'artifacts/');

        $this->assertNotNull($result);
        $this->assertSame('artifacts/landing-page/spring-campaign-019e0000.md', $result['path']);
        $this->assertStringContainsString('name: "Spring Campaign"', $result['content']);
        $this->assertStringContainsString('version: 2', $result['content']);
        $this->assertStringContainsString('Hello body', $result['content']);
        $this->assertStringNotContainsString('old draft', $result['content']);
    }

    public function test_artifact_without_version_content_returns_null(): void
    {
        $artifact = new Artifact(['type' => 'pdf', 'name' => 'Empty', 'current_version' => 1]);
        $artifact->id = '019e0000-0000-7000-8000-0000000000cd';
        $artifact->setRelation('versions', collect([
            new ArtifactVersion(['version' => 1, 'content' => null]),
        ]));

        $this->assertNull($this->renderer->artifact($artifact, 'artifacts/'));
    }

    public function test_renders_memory_into_tier_folder_with_frontmatter(): void
    {
        $memory = new Memory([
            'content' => 'Always verify webhook signatures',
            'tier' => MemoryTier::Canonical,
            'topic' => 'Webhooks',
            'confidence' => 0.9,
            'tags' => ['security', 'webhooks'],
        ]);
        $memory->id = '019e0000-0000-7000-8000-0000000000ef';

        $result = $this->renderer->memory($memory, 'memory/');

        $this->assertStringStartsWith('memory/'.MemoryTier::Canonical->value.'/', $result['path']);
        $this->assertStringContainsString('tier: "'.MemoryTier::Canonical->value.'"', $result['content']);
        $this->assertStringContainsString('Always verify webhook signatures', $result['content']);
        $this->assertStringContainsString('["security", "webhooks"]', $result['content']);
    }

    public function test_frontmatter_stays_valid_yaml_with_a_hostile_topic_and_tags(): void
    {
        $memory = new Memory([
            'content' => 'body',
            'tier' => MemoryTier::Canonical,
            'topic' => "- injected: true\n#evil &anchor",
            'confidence' => 0.5,
            'tags' => ['a, b: c', ']'],
        ]);
        $memory->id = '019e0000-0000-7000-8000-0000000000aa';

        $result = $this->renderer->memory($memory, 'memory/');

        // The frontmatter block must round-trip through a real YAML parser
        // unchanged — no break-out from the attacker-controlled values.
        $this->assertSame(1, preg_match('/^---\n(.*?)\n---\n/s', $result['content'], $m));
        $parsed = Yaml::parse($m[1]);

        $this->assertSame("- injected: true\n#evil &anchor", $parsed['topic']);
        $this->assertSame(['a, b: c', ']'], $parsed['tags']);
        $this->assertSame(MemoryTier::Canonical->value, $parsed['tier']);
    }
}
