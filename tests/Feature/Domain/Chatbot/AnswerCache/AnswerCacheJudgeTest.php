<?php

namespace Tests\Feature\Domain\Chatbot\AnswerCache;

use App\Domain\Chatbot\Models\ChatbotAnswerCacheEntry;
use App\Domain\Chatbot\Services\ChatbotAnswerCache;
use PHPUnit\Framework\Attributes\DataProvider;

class AnswerCacheJudgeTest extends AnswerCacheTestCase
{
    public function test_exact_repeat_is_served_without_a_judge_call(): void
    {
        $bot = $this->chatbot();
        $entry = $this->storeEntry($bot, 'Колко струва доставката?', 'Безплатна над 50 лв.', $this->axis(0));

        $lookup = $this->cache()->lookup($bot, '  колко СТРУВА доставката ', $this->axis(3), $this->cache()->promptHash($bot), 0);

        $this->assertSame('exact', $lookup->decision);
        $this->assertSame('Безплатна над 50 лв.', $lookup->answer);
        $this->assertSame([], $this->gatewayRequests);
        $this->assertSame(1, $entry->refresh()->hit_count);
    }

    public function test_judge_hit_serves_the_adapted_answer(): void
    {
        $bot = $this->chatbot();
        $this->storeEntry($bot, 'Колко струва доставката?', 'Безплатна над 50 лв.', $this->axis(0));
        $this->gatewayReplies = ["```json\n{\"decision\":\"hit\",\"use\":[1],\"answer\":\"Delivery is free over 50 BGN.\"}\n```"];

        $lookup = $this->cache()->lookup($bot, 'How much is shipping?', $this->axis(0, 0.3), $this->cache()->promptHash($bot), 0);

        $this->assertTrue($lookup->isServed());
        $this->assertSame('hit', $lookup->decision);
        $this->assertSame('Delivery is free over 50 BGN.', $lookup->answer);
        $this->assertSame(ChatbotAnswerCache::JUDGE_PURPOSE, $this->gatewayRequests[0]->purpose);
        $this->assertSame(0.0, $this->gatewayRequests[0]->temperature);
    }

    public function test_judge_hit_without_answer_text_serves_the_stored_answer(): void
    {
        $bot = $this->chatbot();
        $this->storeEntry($bot, 'Колко струва доставката?', 'Безплатна над 50 лв.', $this->axis(0));
        $this->gatewayReplies = ['{"decision":"hit","use":[1],"answer":""}'];

        $lookup = $this->cache()->lookup($bot, 'А доставката колко е?', $this->axis(0, 0.2), $this->cache()->promptHash($bot), 0);

        $this->assertSame('Безплатна над 50 лв.', $lookup->answer);
    }

    public function test_judge_combine_merges_answers_and_sources(): void
    {
        $bot = $this->chatbot();
        $this->storeEntry($bot, 'Колко струва доставката?', 'Безплатна над 50 лв.', $this->axis(0), ['sources' => [['chunk_id' => 'c1']]]);
        $this->storeEntry($bot, 'Колко време отнема доставката?', '2 работни дни.', $this->axis(0, 0.4), ['sources' => [['chunk_id' => 'c2']]]);
        $this->gatewayReplies = ['{"decision":"combine","use":[1,2],"answer":"Безплатна над 50 лв., пристига за 2 работни дни."}'];

        $lookup = $this->cache()->lookup($bot, 'Колко струва и колко време отнема доставката?', $this->axis(0, 0.2), $this->cache()->promptHash($bot), 0);

        $this->assertSame('combine', $lookup->decision);
        $this->assertCount(2, $lookup->entries);
        $this->assertEqualsCanonicalizing(['c1', 'c2'], array_column($lookup->sources(), 'chunk_id'));
    }

    /**
     * @return array<string, array{0: string|\Throwable}>
     */
    public static function rejectedVerdicts(): array
    {
        return [
            'explicit reject' => ['{"decision":"reject","use":[]}'],
            'malformed json' => ['sure, candidate 1 looks right'],
            'out of range candidate' => ['{"decision":"hit","use":[7],"answer":"x"}'],
            'hit with two candidates' => ['{"decision":"hit","use":[1,2],"answer":"x"}'],
            'combine without answer' => ['{"decision":"combine","use":[1,2],"answer":""}'],
            'gateway failure' => [new \RuntimeException('provider down')],
        ];
    }

    #[DataProvider('rejectedVerdicts')]
    public function test_anything_but_a_valid_verdict_is_a_miss(string|\Throwable $reply): void
    {
        $bot = $this->chatbot();
        $this->storeEntry($bot, 'Каква е дозата за възрастни?', '2 таблетки.', $this->axis(0));
        $this->storeEntry($bot, 'Каква е дозата за деца?', '1/2 таблетка.', $this->axis(0, 0.1));
        $this->gatewayReplies = [$reply];

        $lookup = $this->cache()->lookup($bot, 'Дозата за тийнейджъри?', $this->axis(0, 0.05), $this->cache()->promptHash($bot), 0);

        $this->assertFalse($lookup->isServed());
        $this->assertSame('miss', $lookup->status());
        $this->assertSame(0, (int) ChatbotAnswerCacheEntry::sum('hit_count'));
    }

    public function test_no_candidate_within_the_prefilter_skips_the_judge(): void
    {
        $bot = $this->chatbot();
        $this->storeEntry($bot, 'Работно време?', '9-18 ч.', $this->axis(0));

        $lookup = $this->cache()->lookup($bot, 'Имате ли паркинг?', $this->axis(4), $this->cache()->promptHash($bot), 0);

        $this->assertSame('none', $lookup->decision);
        $this->assertSame([], $this->gatewayRequests);
    }

    public function test_judge_never_sees_distances(): void
    {
        $bot = $this->chatbot();
        $this->storeEntry($bot, 'Работно време?', '9-18 ч.', $this->axis(0));

        $this->cache()->lookup($bot, 'Кога работите?', $this->axis(0, 0.3), $this->cache()->promptHash($bot), 0);

        $prompt = $this->gatewayRequests[0]->systemPrompt.$this->gatewayRequests[0]->userPrompt;
        $this->assertStringNotContainsStringIgnoringCase('distance', $prompt);
        $this->assertStringNotContainsStringIgnoringCase('similarity', $prompt);
        $this->assertDoesNotMatchRegularExpression('/0\.\d{2,}/', $this->gatewayRequests[0]->userPrompt);
    }
}
