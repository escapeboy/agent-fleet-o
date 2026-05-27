<?php

namespace Tests\Unit\Infrastructure\AI\Services;

use App\Infrastructure\AI\Jobs\ExportToPhoenixJob;
use App\Infrastructure\AI\Services\ClaudeCodeTranscriptParser;
use App\Infrastructure\AI\Services\TranscriptIngestor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TranscriptIngestorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'llmops.transcript_ingest.enabled' => true,
            'llmops.phoenix.enabled' => true,
            'llmops.phoenix.endpoint' => 'http://phoenix:6006',
            'llmops.phoenix.mask_content' => false,
        ]);
        Bus::fake();
    }

    private function ingestor(): TranscriptIngestor
    {
        return new TranscriptIngestor(new ClaudeCodeTranscriptParser);
    }

    private function fixture(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/claude-code-transcript.jsonl'));
    }

    /**
     * @return Collection<int, ExportToPhoenixJob>
     */
    private function dispatchedJobs(): Collection
    {
        return Bus::dispatched(ExportToPhoenixJob::class);
    }

    private function prop(object $job, string $name): mixed
    {
        return (new \ReflectionProperty($job, $name))->getValue($job);
    }

    public function test_noop_when_transcript_ingest_disabled(): void
    {
        config(['llmops.transcript_ingest.enabled' => false]);

        $result = $this->ingestor()->ingest($this->fixture());

        $this->assertFalse($result['ingested']);
        $this->assertSame('disabled', $result['reason']);
        Bus::assertNotDispatched(ExportToPhoenixJob::class);
    }

    public function test_noop_when_phoenix_disabled(): void
    {
        config(['llmops.phoenix.enabled' => false]);

        $result = $this->ingestor()->ingest($this->fixture());

        $this->assertFalse($result['ingested']);
        Bus::assertNotDispatched(ExportToPhoenixJob::class);
    }

    public function test_noop_on_empty_transcript(): void
    {
        $result = $this->ingestor()->ingest('   ');

        $this->assertFalse($result['ingested']);
        $this->assertSame('empty', $result['reason']);
        Bus::assertNotDispatched(ExportToPhoenixJob::class);
    }

    public function test_emits_root_and_child_spans_sharing_one_trace(): void
    {
        $result = $this->ingestor()->ingest($this->fixture(), ['agent_id' => 'agent-1']);

        // 1 root + 2 assistant LLM spans + 1 tool span.
        $this->assertTrue($result['ingested']);
        $this->assertSame(4, $result['spans_emitted']);
        $this->assertSame(4, $result['turns']);
        $this->assertSame(1, $result['tool_calls']);

        $jobs = $this->dispatchedJobs();
        $this->assertCount(4, $jobs);

        // All spans share the returned trace id.
        $traceIds = $jobs->map(fn ($j) => $this->prop($j, 'traceId'))->unique();
        $this->assertCount(1, $traceIds);
        $this->assertSame($result['trace_id'], $traceIds->first());

        $root = $jobs->first(fn ($j) => $this->prop($j, 'spanName') === 'local_agent.session');
        $this->assertNotNull($root);
        $this->assertNull($this->prop($root, 'parentSpanId'));
        $rootSpanId = $this->prop($root, 'spanId');

        // Every non-root span parents to the root span id.
        $children = $jobs->filter(fn ($j) => $this->prop($j, 'spanName') !== 'local_agent.session');
        foreach ($children as $child) {
            $this->assertSame($rootSpanId, $this->prop($child, 'parentSpanId'));
        }

        $toolSpan = $jobs->first(fn ($j) => $this->prop($j, 'spanName') === 'local_agent.tool.Bash');
        $this->assertNotNull($toolSpan);
        $this->assertSame('TOOL', $this->prop($toolSpan, 'attributes')['openinference.span.kind']);
    }

    public function test_masks_content_but_keeps_token_counts(): void
    {
        $this->ingestor()->ingest($this->fixture(), ['mask' => true]);

        $jobs = $this->dispatchedJobs();

        $turn = $jobs->first(fn ($j) => $this->prop($j, 'spanName') === 'local_agent.turn');
        $attrs = $this->prop($turn, 'attributes');
        $this->assertSame('[REDACTED]', $attrs['llm.output_messages.0.message.content']);
        $this->assertSame(2000, $attrs['llm.token_count.prompt']);

        $tool = $jobs->first(fn ($j) => $this->prop($j, 'spanName') === 'local_agent.tool.Bash');
        $this->assertSame('[REDACTED]', $this->prop($tool, 'attributes')['tool.parameters']);
    }

    public function test_caps_total_spans_and_flags_truncation(): void
    {
        // One assistant turn with far more tool calls than the span ceiling.
        $blocks = [['type' => 'text', 'text' => 'go']];
        for ($i = 0; $i < TranscriptIngestor::MAX_SPANS + 100; $i++) {
            $blocks[] = ['type' => 'tool_use', 'id' => "t$i", 'name' => 'Bash', 'input' => ['command' => 'x']];
        }
        $line = (string) json_encode([
            'type' => 'assistant',
            'timestamp' => '2026-05-27T10:00:00.000Z',
            'message' => ['role' => 'assistant', 'model' => 'm', 'content' => $blocks, 'usage' => ['output_tokens' => 1]],
        ]);

        $result = $this->ingestor()->ingest($line);

        $this->assertTrue($result['truncated']);
        $this->assertSame(TranscriptIngestor::MAX_SPANS, $result['spans_emitted']);
        Bus::assertDispatchedTimes(ExportToPhoenixJob::class, TranscriptIngestor::MAX_SPANS);
    }

    public function test_uses_provided_trace_id(): void
    {
        $trace = str_repeat('a', 32);

        $result = $this->ingestor()->ingest($this->fixture(), ['trace_id' => $trace]);

        $this->assertSame($trace, $result['trace_id']);
        $this->assertSame($trace, $this->prop($this->dispatchedJobs()->first(), 'traceId'));
    }
}
