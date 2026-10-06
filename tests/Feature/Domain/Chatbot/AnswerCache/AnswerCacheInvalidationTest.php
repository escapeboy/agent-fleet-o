<?php

namespace Tests\Feature\Domain\Chatbot\AnswerCache;

use App\Domain\Chatbot\Models\ChatbotAnswerCacheEntry;
use App\Domain\Chatbot\Models\ChatbotKnowledgeSource;

class AnswerCacheInvalidationTest extends AnswerCacheTestCase
{
    public function test_every_knowledge_source_change_drops_the_chatbot_cache(): void
    {
        $bot = $this->chatbot();
        $other = $this->chatbot();
        $this->storeEntry($other, 'q', 'other bot keeps this', $this->axis(0));

        $steps = [
            'create' => fn () => ChatbotKnowledgeSource::create([
                'chatbot_id' => $bot->id, 'team_id' => $bot->team_id, 'type' => 'url',
                'name' => 'Docs', 'source_url' => 'https://example.com', 'status' => 'pending',
            ]),
            'ready' => fn () => ChatbotKnowledgeSource::where('chatbot_id', $bot->id)->first()->update(['status' => 'ready']),
            'toggle' => fn () => ChatbotKnowledgeSource::where('chatbot_id', $bot->id)->first()->update(['is_enabled' => false]),
            'delete' => fn () => ChatbotKnowledgeSource::where('chatbot_id', $bot->id)->first()->delete(),
            'restore' => fn () => ChatbotKnowledgeSource::withTrashed()->where('chatbot_id', $bot->id)->first()->restore(),
        ];

        $generation = (int) $bot->refresh()->answer_cache_generation;
        foreach ($steps as $name => $step) {
            $this->storeEntry($bot->refresh(), "q {$name}", 'a', $this->axis(1));

            $step();

            $bot->refresh();
            $this->assertGreaterThan($generation, (int) $bot->answer_cache_generation, "generation not bumped on {$name}");
            $generation = (int) $bot->answer_cache_generation;
            $this->assertSame(0, ChatbotAnswerCacheEntry::where('chatbot_id', $bot->id)->count(), "entries kept after {$name}");
        }

        $this->assertSame(1, ChatbotAnswerCacheEntry::where('chatbot_id', $other->id)->count());
    }

    public function test_turning_the_cache_off_deletes_its_entries(): void
    {
        $bot = $this->chatbot();
        $this->storeEntry($bot, 'q', 'a', $this->axis(0));

        $settings = $this->cache()->updateSettings($bot, ['enabled' => false]);

        $this->assertFalse($settings['enabled']);
        $this->assertSame(0, ChatbotAnswerCacheEntry::count());
    }
}
