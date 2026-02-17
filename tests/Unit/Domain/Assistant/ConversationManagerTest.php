<?php

namespace Tests\Unit\Domain\Assistant;

use App\Domain\Assistant\Models\AssistantMessage;
use App\Domain\Assistant\Services\ConversationManager;
use PHPUnit\Framework\TestCase;

class ConversationManagerTest extends TestCase
{
    private ConversationManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new ConversationManager;
    }

    public function test_recent_messages_score_higher_than_old_ones(): void
    {
        $old = $this->makeMessage(content: 'old message');
        $recent = $this->makeMessage(content: 'recent message');

        $oldScore = $this->manager->scoreMessage($old, index: 0, total: 10);
        $recentScore = $this->manager->scoreMessage($recent, index: 9, total: 10);

        $this->assertGreaterThan($oldScore, $recentScore);
    }

    public function test_tool_call_messages_get_bonus(): void
    {
        $plain = $this->makeMessage(content: 'plain message');
        $withTools = $this->makeMessage(content: 'tool message', toolCalls: [['name' => 'test']]);

        $plainScore = $this->manager->scoreMessage($plain, index: 5, total: 10);
        $toolScore = $this->manager->scoreMessage($withTools, index: 5, total: 10);

        $this->assertGreaterThan($plainScore, $toolScore);
    }

    public function test_tool_result_messages_get_bonus(): void
    {
        $plain = $this->makeMessage(content: 'plain message');
        $withResults = $this->makeMessage(content: 'result message', toolResults: [['output' => 'data']]);

        $plainScore = $this->manager->scoreMessage($plain, index: 5, total: 10);
        $resultScore = $this->manager->scoreMessage($withResults, index: 5, total: 10);

        $this->assertGreaterThan($plainScore, $resultScore);
    }

    public function test_very_long_messages_get_penalized(): void
    {
        $short = $this->makeMessage(content: str_repeat('a', 500));
        $long = $this->makeMessage(content: str_repeat('a', 6000));

        $shortScore = $this->manager->scoreMessage($short, index: 5, total: 10);
        $longScore = $this->manager->scoreMessage($long, index: 5, total: 10);

        $this->assertGreaterThan($longScore, $shortScore);
    }

    public function test_first_three_messages_get_anchor_bonus(): void
    {
        $anchored = $this->makeMessage(content: 'anchored');
        $middle = $this->makeMessage(content: 'middle');

        $anchoredScore = $this->manager->scoreMessage($anchored, index: 1, total: 20);
        $middleScore = $this->manager->scoreMessage($middle, index: 10, total: 20);

        $this->assertGreaterThan($middleScore, $anchoredScore);
    }

    public function test_last_three_messages_get_anchor_bonus(): void
    {
        $anchored = $this->makeMessage(content: 'anchored');
        $middle = $this->makeMessage(content: 'middle');

        $anchoredScore = $this->manager->scoreMessage($anchored, index: 18, total: 20);
        $middleScore = $this->manager->scoreMessage($middle, index: 10, total: 20);

        $this->assertGreaterThan($middleScore, $anchoredScore);
    }

    public function test_pinned_messages_get_highest_bonus(): void
    {
        $normal = $this->makeMessage(content: 'normal');
        $pinned = $this->makeMessage(content: 'pinned', metadata: ['pinned' => true]);

        $normalScore = $this->manager->scoreMessage($normal, index: 5, total: 10);
        $pinnedScore = $this->manager->scoreMessage($pinned, index: 5, total: 10);

        $this->assertGreaterThan($normalScore, $pinnedScore);
        // Pinned bonus (5.0) should exceed any other individual bonus
        $this->assertGreaterThanOrEqual(5.0, $pinnedScore - $normalScore);
    }

    public function test_single_message_gets_full_recency_and_anchor(): void
    {
        $msg = $this->makeMessage(content: 'only message');

        $score = $this->manager->scoreMessage($msg, index: 0, total: 1);

        // recency=3.0 (ratio=1.0) + anchor=3.0 (first and last) = 6.0
        $this->assertEquals(6.0, $score);
    }

    private function makeMessage(
        string $content = '',
        ?array $toolCalls = null,
        ?array $toolResults = null,
        array $metadata = [],
    ): AssistantMessage {
        $message = new AssistantMessage;
        $message->content = $content;
        $message->tool_calls = $toolCalls;
        $message->tool_results = $toolResults;
        $message->metadata = $metadata;

        return $message;
    }
}
