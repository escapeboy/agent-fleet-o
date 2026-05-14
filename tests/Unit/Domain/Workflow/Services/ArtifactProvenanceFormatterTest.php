<?php

namespace Tests\Unit\Domain\Workflow\Services;

use App\Domain\Workflow\Services\ArtifactProvenanceFormatter;
use PHPUnit\Framework\TestCase;

class ArtifactProvenanceFormatterTest extends TestCase
{
    private ArtifactProvenanceFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new ArtifactProvenanceFormatter;
    }

    public function test_renders_header_with_no_context(): void
    {
        $markdown = $this->formatter->fromContext([]);

        $this->assertStringContainsString('## Provenance', $markdown);
    }

    public function test_renders_signal_section_when_title_present(): void
    {
        $markdown = $this->formatter->fromContext([
            'signal_title' => 'NullReferenceException in checkout',
            'signal_source' => 'sentry',
        ]);

        $this->assertStringContainsString('### Triggering signal', $markdown);
        $this->assertStringContainsString('NullReferenceException in checkout', $markdown);
        $this->assertStringContainsString('sentry', $markdown);
    }

    public function test_renders_pipeline_steps_table(): void
    {
        $markdown = $this->formatter->fromContext([
            'steps' => [
                ['agent' => 'RCA Agent', 'status' => 'completed', 'cost' => 12],
                ['agent' => 'Patch Agent', 'status' => 'completed', 'cost' => 45],
            ],
        ]);

        $this->assertStringContainsString('| # | Agent | Status | Cost |', $markdown);
        $this->assertStringContainsString('RCA Agent', $markdown);
        $this->assertStringContainsString('Patch Agent', $markdown);
        $this->assertStringContainsString('12 cr', $markdown);
    }

    public function test_handles_missing_step_fields_gracefully(): void
    {
        $markdown = $this->formatter->fromContext([
            'steps' => [
                [], // empty
                ['agent' => 'Solo'],
            ],
        ]);

        $this->assertStringContainsString('Solo', $markdown);
        $this->assertStringContainsString('| 1 | — | — | — |', $markdown);
    }
}
