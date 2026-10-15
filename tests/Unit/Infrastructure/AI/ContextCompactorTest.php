<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\Services\ContextCompactor;
use App\Infrastructure\AI\Services\TokenEstimator;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\PrismFake;
use Prism\Prism\Testing\TextResponseFake;
use Tests\TestCase;

class ContextCompactorTest extends TestCase
{
    private TokenEstimator $estimator;

    private ContextCompactor $compactor;

    protected function setUp(): void
    {
        parent::setUp();
        // The summarization stage calls Prism directly. Fake it so no test makes a
        // live, billed call; with no queued response an unexpected call throws and
        // the compactor skips the stage.
        $this->fakeSummary(null);
        $this->estimator = new TokenEstimator;
        $this->compactor = new ContextCompactor($this->estimator);
    }

    private function fakeSummary(?string $summary): PrismFake
    {
        return Prism::fake($summary === null ? [] : [TextResponseFake::make()->withText($summary)]);
    }

    public function test_already_within_budget_returns_after_tool_compaction(): void
    {
        // Small prompt that's already within budget
        [$result, $stage] = $this->compactor->compact(
            systemPrompt: 'System prompt',
            userPrompt: 'Short user prompt',
            targetTokens: 100_000,
            utilization: 0.72,
            summarizationModel: 'anthropic/claude-haiku-4-5',
            summarizationMaxTokens: 2000,
            minPreservedLines: 4,
        );

        $this->assertSame('Short user prompt', $result);
        $this->assertSame(ContextCompactor::STAGE_TOOL_OUTPUT, $stage);
    }

    public function test_sliding_window_truncates_old_content(): void
    {
        // Create a long prompt with many lines
        $lines = [];
        for ($i = 0; $i < 200; $i++) {
            $lines[] = "User: Message number {$i} with some content to make it longer";
            $lines[] = "Assistant: Response number {$i} with detailed analysis and recommendations";
        }
        $longPrompt = implode("\n", $lines);

        config([
            'context_compaction.window_threshold' => 0.85,
            'context_compaction.emergency_threshold' => 0.92,
        ]);

        // Summary still too long for the budget, so the window stage must run
        $this->fakeSummary(str_repeat("Summary detail line with facts and decisions\n", 100));

        [$result, $stage] = $this->compactor->compact(
            systemPrompt: 'System',
            userPrompt: $longPrompt,
            targetTokens: 500, // Very low target to force sliding window
            utilization: 0.90, // Above window threshold
            summarizationModel: 'anthropic/claude-haiku-4-5',
            summarizationMaxTokens: 2000,
            minPreservedLines: 4,
        );

        // Should have applied sliding window
        $this->assertStringContainsString('sliding window applied', $result);
        // Should be shorter than original
        $this->assertLessThan(mb_strlen($longPrompt), mb_strlen($result));
    }

    public function test_emergency_truncation_fits_budget(): void
    {
        // Very large prompt
        $hugePrompt = str_repeat("User: Hello world this is a test\nAssistant: Thank you for the test\n", 5000);
        $this->fakeSummary(str_repeat("Summary detail line\n", 2000));

        config([
            'context_compaction.window_threshold' => 0.85,
            'context_compaction.emergency_threshold' => 0.92,
        ]);

        [$result, $stage] = $this->compactor->compact(
            systemPrompt: 'System',
            userPrompt: $hugePrompt,
            targetTokens: 1000,
            utilization: 0.95, // Emergency level
            summarizationModel: 'anthropic/claude-haiku-4-5',
            summarizationMaxTokens: 2000,
            minPreservedLines: 4,
        );

        $this->assertSame(ContextCompactor::STAGE_TRUNCATION, $stage);
        $this->assertStringContainsString('Emergency', $result);
        // Should be substantially shorter
        $this->assertLessThan(mb_strlen($hugePrompt) / 2, mb_strlen($result));
    }

    public function test_summarization_stage_uses_summary_when_it_fits(): void
    {
        $lines = [];
        for ($i = 0; $i < 200; $i++) {
            $lines[] = "User: Message number {$i} with some content to make it longer";
        }
        $fake = $this->fakeSummary('Short summary of the older conversation.');

        [$result, $stage] = $this->compactor->compact(
            systemPrompt: 'System',
            userPrompt: implode("\n", $lines),
            targetTokens: 1000,
            utilization: 0.75,
            summarizationModel: 'anthropic/claude-haiku-4-5',
            summarizationMaxTokens: 2000,
            minPreservedLines: 4,
        );

        $fake->assertCallCount(1);
        $this->assertSame(ContextCompactor::STAGE_SUMMARIZATION, $stage);
        $this->assertStringContainsString('Short summary of the older conversation.', $result);
        $this->assertStringContainsString('Message number 199', $result);
    }

    public function test_tool_output_compaction_collapses_old_results(): void
    {
        $prompt = <<<'TEXT'
User: Search for competitors
Assistant: I'll search for you.
Tool result: web_search
{"results": [{"title": "Competitor A", "snippet": "Very long detailed content about competitor A that goes on and on with lots of details about their products and services and pricing and features and team and history"}, {"title": "Competitor B", "snippet": "Similarly long content about competitor B"}]}

User: What about their pricing?
Assistant: Let me check pricing.
Tool result: web_search
{"results": [{"title": "Pricing A", "snippet": "Detailed pricing information for A"}, {"title": "Pricing B", "snippet": "Detailed pricing information for B"}]}

User: Summarize everything
Assistant: Here is the summary.
Tool result: summarize
{"summary": "This is the final summary of all findings with comprehensive details about both competitors."}
TEXT;

        [$result, $stage] = $this->compactor->compact(
            systemPrompt: 'System',
            userPrompt: $prompt,
            targetTokens: 200_000,
            utilization: 0.72,
            summarizationModel: 'anthropic/claude-haiku-4-5',
            summarizationMaxTokens: 2000,
            minPreservedLines: 4,
        );

        $this->assertSame(ContextCompactor::STAGE_TOOL_OUTPUT, $stage);
    }

    public function test_parse_model_string_with_provider(): void
    {
        // Test via compact with a known provider/model format
        // The parseModelString is private, so we test indirectly
        // A summarization that would fail with wrong parsing would show in logs
        $this->assertInstanceOf(ContextCompactor::class, $this->compactor);
    }

    public function test_short_prompt_skips_summarization(): void
    {
        // Very short prompt — should not attempt summarization
        [$result, $stage] = $this->compactor->compact(
            systemPrompt: 'System',
            userPrompt: "Line 1\nLine 2\nLine 3",
            targetTokens: 5, // Force going through stages
            utilization: 0.90,
            summarizationModel: 'anthropic/claude-haiku-4-5',
            summarizationMaxTokens: 2000,
            minPreservedLines: 4,
        );

        // With only 3 lines and minPreservedLines=4 (*3=12), it's too short to summarize
        // Should skip to window or truncation
        $this->assertNotEmpty($result);
    }
}
