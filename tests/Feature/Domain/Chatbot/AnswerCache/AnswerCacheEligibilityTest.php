<?php

namespace Tests\Feature\Domain\Chatbot\AnswerCache;

use App\Domain\Chatbot\Services\ChatbotAnswerCache;
use App\Domain\Tool\Models\Tool;
use Illuminate\Support\Str;

class AnswerCacheEligibilityTest extends AnswerCacheTestCase
{
    public function test_first_standalone_question_is_eligible(): void
    {
        $this->assertNull($this->cache()->ineligibilityReason($this->chatbot(), 'Колко струва доставката?', false));
    }

    public function test_platform_switch_off_disables_every_chatbot(): void
    {
        config(['chatbot_answer_cache.enabled' => false]);

        $this->assertSame('disabled', $this->cache()->ineligibilityReason($this->chatbot(), 'q', false));
    }

    public function test_chatbot_flag_is_off_by_default(): void
    {
        $bot = $this->chatbot(attributes: ['config' => []]);

        $this->assertSame('disabled', $this->cache()->ineligibilityReason($bot, 'q', false));
    }

    public function test_follow_up_questions_are_not_cached(): void
    {
        $this->assertSame('follow_up', $this->cache()->ineligibilityReason($this->chatbot(), 'а колко струва той?', true));
    }

    public function test_questions_with_contact_data_are_not_cached(): void
    {
        $bot = $this->chatbot();

        $this->assertSame('personal_data', $this->cache()->ineligibilityReason($bot, 'Пишете ми на ivan@example.com', false));
        $this->assertSame('personal_data', $this->cache()->ineligibilityReason($bot, 'Обадете се на 0888 123 456', false));
    }

    public function test_workflow_chatbots_are_not_cached(): void
    {
        $bot = $this->chatbot();
        $bot->forceFill(['workflow_id' => (string) Str::uuid7()]);

        $this->assertSame('workflow', $this->cache()->ineligibilityReason($bot, 'q', false));
    }

    public function test_agents_with_tools_are_not_cached(): void
    {
        $bot = $this->chatbot();
        $tool = Tool::factory()->create(['team_id' => $bot->team_id]);
        $bot->agent->tools()->attach($tool->id);

        $this->assertSame('agent_tools', $this->cache()->ineligibilityReason($bot, 'q', false));
    }

    public function test_personal_data_screen_does_not_flag_prices_or_years(): void
    {
        $this->assertFalse(ChatbotAnswerCache::containsPersonalData('Струва ли 1 000 000 лв. през 2026 г.?'));
        $this->assertFalse(ChatbotAnswerCache::containsPersonalData('Каква е дозата за деца под 12 години?'));
        $this->assertTrue(ChatbotAnswerCache::containsPersonalData('ЕГН 8501011234'));
        $this->assertTrue(ChatbotAnswerCache::containsPersonalData('+359 888 123 456'));
    }

    public function test_normalize_ignores_case_spacing_and_trailing_punctuation(): void
    {
        $this->assertSame(
            ChatbotAnswerCache::normalize('  Колко   СТРУВА доставката?! '),
            ChatbotAnswerCache::normalize('колко струва доставката'),
        );
    }

    public function test_prompt_hash_changes_when_the_agent_changes(): void
    {
        $bot = $this->chatbot();
        $before = $this->cache()->promptHash($bot);

        $this->travel(1)->seconds();
        $bot->agent->update(['backstory' => 'New instructions']);
        $bot->refresh();

        $this->assertNotSame($before, $this->cache()->promptHash($bot));
    }
}
