<?php

namespace Tests\Feature\Domain\Chatbot\AnswerCache;

class AnswerCacheIsolationTest extends AnswerCacheTestCase
{
    public function test_an_answer_stored_for_one_team_never_reaches_another_team(): void
    {
        $a = $this->chatbot();
        $b = $this->chatbot();
        $this->assertNotSame($a->team_id, $b->team_id);

        $this->storeEntry($a, 'Колко струва доставката?', 'Team A secret price: 7 лв.', $this->axis(0));

        // Identical prompt hash on purpose: only tenant filters may separate them.
        $hash = $this->cache()->promptHash($a);
        $this->assertSame([], $this->cache()->candidates($b, 0, $hash, $this->axis(0)));

        $lookup = $this->cache()->lookup($b, 'Колко струва доставката?', $this->axis(0), $hash, 0);
        $this->assertFalse($lookup->isServed());
        $this->assertSame('none', $lookup->decision);
        $this->assertSame([], $this->gatewayRequests, 'the judge must not even see another tenant\'s answers');
    }

    public function test_an_answer_stays_inside_its_chatbot_within_one_team(): void
    {
        $team = $this->team();
        $a = $this->chatbot($team);
        $b = $this->chatbot($team);

        $this->storeEntry($a, 'Колко струва доставката?', 'Bot A answer', $this->axis(0));

        $lookup = $this->cache()->lookup($b, 'Колко струва доставката?', $this->axis(0), $this->cache()->promptHash($b), 0);
        $this->assertFalse($lookup->isServed());
    }

    public function test_entries_of_an_old_generation_or_another_prompt_are_not_candidates(): void
    {
        $bot = $this->chatbot();
        $this->storeEntry($bot, 'q', 'old knowledge', $this->axis(0), ['generation' => 0]);
        $this->storeEntry($bot, 'q2', 'other prompt', $this->axis(0), ['prompt_hash' => str_repeat('a', 64)]);

        $this->assertSame([], $this->cache()->candidates($bot, 1, $this->cache()->promptHash($bot), $this->axis(0)));
        $this->assertCount(1, $this->cache()->candidates($bot, 0, $this->cache()->promptHash($bot), $this->axis(0)));
    }

    public function test_expired_entries_are_not_candidates(): void
    {
        $bot = $this->chatbot();
        $entry = $this->storeEntry($bot, 'q', 'a', $this->axis(0));
        $entry->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertSame([], $this->cache()->candidates($bot, 0, $this->cache()->promptHash($bot), $this->axis(0)));
    }
}
