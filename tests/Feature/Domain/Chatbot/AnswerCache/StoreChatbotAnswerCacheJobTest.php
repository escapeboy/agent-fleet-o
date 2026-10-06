<?php

namespace Tests\Feature\Domain\Chatbot\AnswerCache;

use App\Domain\Chatbot\Jobs\StoreChatbotAnswerCacheJob;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotAnswerCacheEntry;
use App\Domain\Chatbot\Models\ChatbotKnowledgeSource;
use App\Domain\Chatbot\Services\ChatbotAnswerCache;
use PHPUnit\Framework\Attributes\DataProvider;

class StoreChatbotAnswerCacheJobTest extends AnswerCacheTestCase
{
    private function job(Chatbot $bot, string $answer, array $overrides = []): StoreChatbotAnswerCacheJob
    {
        return new StoreChatbotAnswerCacheJob(
            chatbotId: $bot->id,
            generation: $overrides['generation'] ?? (int) $bot->answer_cache_generation,
            promptHash: $overrides['prompt_hash'] ?? $this->cache()->promptHash($bot),
            question: $overrides['question'] ?? 'Колко струва доставката?',
            vector: $this->axis(0),
            answer: $answer,
            sources: [['chunk_id' => 'c1']],
            confidence: 0.9,
            generationTokens: 900,
            generationCostCredits: 14,
        );
    }

    private function runJob(StoreChatbotAnswerCacheJob $job): void
    {
        $job->handle(app(ChatbotAnswerCache::class));
    }

    public function test_a_good_answer_is_stored_after_the_store_check(): void
    {
        $bot = $this->chatbot();
        $this->gatewayReplies = ['{"store": true, "reason": "general info"}'];

        $this->runJob($this->job($bot, 'Доставката е безплатна над 50 лв.'));

        $entry = ChatbotAnswerCacheEntry::sole();
        $this->assertSame($bot->team_id, $entry->team_id);
        $this->assertSame('колко струва доставката', $entry->question);
        $this->assertSame(14, $entry->generation_cost_credits);
        $this->assertSame(ChatbotAnswerCache::CHECK_PURPOSE, $this->gatewayRequests[0]->purpose);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonAnswers(): array
    {
        return [
            'empty' => ['   '],
            'bulgarian dont know' => ['Съжалявам, не знам отговора на този въпрос.'],
            'no information' => ['Нямам информация по този въпрос.'],
            'english dont know' => ["I'm not sure about that."],
            'fallback message' => ['Моля, свържете се с нас на support.'],
            'contact data in answer' => ['Обадете се на Иван на 0888 123 456.'],
        ];
    }

    #[DataProvider('nonAnswers')]
    public function test_non_answers_are_dropped_without_an_llm_call(string $answer): void
    {
        $bot = $this->chatbot(attributes: ['fallback_message' => 'Моля, свържете се с нас на support.']);

        $this->runJob($this->job($bot, $answer));

        $this->assertSame(0, ChatbotAnswerCacheEntry::count());
        $this->assertSame([], $this->gatewayRequests);
    }

    public function test_store_check_rejection_or_failure_drops_the_answer(): void
    {
        $bot = $this->chatbot();
        $this->gatewayReplies = ['{"store": false, "reason": "names a person"}', new \RuntimeException('down'), 'not json'];

        $this->runJob($this->job($bot, 'Мария ще ви върне парите до петък.'));
        $this->runJob($this->job($bot, 'Доставката е безплатна.'));
        $this->runJob($this->job($bot, 'Доставката е безплатна.'));

        $this->assertSame(0, ChatbotAnswerCacheEntry::count());
    }

    public function test_answer_is_dropped_when_knowledge_changed_meanwhile(): void
    {
        $bot = $this->chatbot();
        $job = $this->job($bot, 'Доставката е безплатна над 50 лв.');

        $this->cache()->invalidate($bot->id);
        $this->runJob($job);

        $this->assertSame(0, ChatbotAnswerCacheEntry::count());
        $this->assertSame([], $this->gatewayRequests);
    }

    public function test_answer_is_dropped_while_a_source_is_indexing(): void
    {
        $bot = $this->chatbot();
        ChatbotKnowledgeSource::create([
            'chatbot_id' => $bot->id,
            'team_id' => $bot->team_id,
            'type' => 'url',
            'name' => 'Docs',
            'source_url' => 'https://example.com',
            'status' => 'indexing',
        ]);
        $bot->refresh();

        $this->runJob($this->job($bot, 'Доставката е безплатна над 50 лв.'));

        $this->assertSame(0, ChatbotAnswerCacheEntry::count());
    }

    public function test_an_expired_entry_does_not_block_storing_the_question_again(): void
    {
        $bot = $this->chatbot();
        $old = $this->storeEntry($bot, 'Колко струва доставката?', 'стар отговор', $this->axis(0));
        $old->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->gatewayReplies = ['{"store": true}'];

        $this->runJob($this->job($bot, 'Доставката е безплатна над 50 лв.'));

        $this->assertSame(['Доставката е безплатна над 50 лв.'], ChatbotAnswerCacheEntry::pluck('answer')->all());
    }

    public function test_lru_cap_drops_the_least_recently_used_entries(): void
    {
        $bot = $this->chatbot(attributes: ['config' => ['answer_cache' => ['enabled' => true, 'max_entries' => 2]]]);
        $old = $this->storeEntry($bot, 'first', 'a1', $this->axis(0));
        $this->travel(1)->minutes();
        $used = $this->storeEntry($bot, 'second', 'a2', $this->axis(1));
        $this->travel(1)->minutes();
        $used->forceFill(['last_hit_at' => now()])->save();
        $this->travel(1)->minutes();

        $this->storeEntry($bot, 'third', 'a3', $this->axis(2));

        $this->assertSame(['second', 'third'], ChatbotAnswerCacheEntry::orderBy('question')->pluck('question')->all());
        $this->assertNull(ChatbotAnswerCacheEntry::find($old->id));
    }
}
