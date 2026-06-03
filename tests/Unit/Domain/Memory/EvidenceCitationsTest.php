<?php

namespace Tests\Unit\Domain\Memory;

use App\Domain\Memory\Services\MemoryContextInjector;
use ReflectionMethod;
use Tests\TestCase;

class EvidenceCitationsTest extends TestCase
{
    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod(MemoryContextInjector::class, $method);
        $m->setAccessible(true);

        return $m->invokeArgs(app(MemoryContextInjector::class), $args);
    }

    public function test_default_config_is_off(): void
    {
        $this->assertFalse((bool) config('memory.evidence_citations.enabled'));
    }

    public function test_citation_token_empty_when_disabled(): void
    {
        config(['memory.evidence_citations.enabled' => false]);
        $this->assertSame('', $this->invokePrivate('citationToken', '019e8cb3-7700-7246-ad45-a8894c934e9a'));
    }

    public function test_citation_token_rendered_when_enabled(): void
    {
        config(['memory.evidence_citations.enabled' => true]);
        $this->assertSame('[[mem:019e8cb3]] ', $this->invokePrivate('citationToken', '019e8cb3-7700-7246-ad45-a8894c934e9a'));
    }

    public function test_citation_token_empty_when_no_id_even_if_enabled(): void
    {
        config(['memory.evidence_citations.enabled' => true]);
        $this->assertSame('', $this->invokePrivate('citationToken', null));
    }

    public function test_passage_prefers_chunk_context_when_enabled(): void
    {
        config(['memory.evidence_citations.enabled' => true]);
        $this->assertSame('passage with situating context', $this->invokePrivate('passageText', 'raw content', 'passage with situating context'));
    }

    public function test_passage_falls_back_to_content_when_no_chunk(): void
    {
        config(['memory.evidence_citations.enabled' => true]);
        $this->assertSame('raw content', $this->invokePrivate('passageText', 'raw content', null));
    }

    public function test_passage_uses_content_when_disabled_even_with_chunk(): void
    {
        config(['memory.evidence_citations.enabled' => false]);
        $this->assertSame('raw content', $this->invokePrivate('passageText', 'raw content', 'passage with situating context'));
    }

    public function test_header_adds_citation_instruction_only_when_enabled(): void
    {
        config(['memory.evidence_citations.enabled' => false]);
        $this->assertStringNotContainsString('Cite any fact', (string) $this->invokePrivate('contextHeader'));

        config(['memory.evidence_citations.enabled' => true]);
        $header = (string) $this->invokePrivate('contextHeader');
        $this->assertStringContainsString('## Relevant Context', $header);
        $this->assertStringContainsString('Cite any fact you use with the [[mem:id]] token', $header);
    }
}
