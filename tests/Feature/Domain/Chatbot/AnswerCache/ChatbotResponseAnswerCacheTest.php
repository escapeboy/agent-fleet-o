<?php

namespace Tests\Feature\Domain\Chatbot\AnswerCache;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Chatbot\Jobs\StoreChatbotAnswerCacheJob;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotMessage;
use App\Domain\Chatbot\Models\ChatbotSession;
use App\Domain\Chatbot\Services\ChatbotResponseService;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\Contracts\EmbeddingProviderInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;

class ChatbotResponseAnswerCacheTest extends AnswerCacheTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Cache::store('redis')->flush();

        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embedForTeam')->andReturn($this->axis(0));
        $embedding->shouldReceive('formatForPgvector')->andReturnUsing(fn (array $v) => '['.implode(',', $v).']');
        $this->app->instance(EmbeddingProviderInterface::class, $embedding);
    }

    private function newSession(Chatbot $bot): ChatbotSession
    {
        return ChatbotSession::create([
            'chatbot_id' => $bot->id,
            'team_id' => $bot->team_id,
            'channel' => 'web_widget',
            'started_at' => now(),
        ]);
    }

    private function agentReplies(?string $reply): void
    {
        $agent = Mockery::mock(ExecuteAgentAction::class);
        if ($reply === null) {
            $agent->shouldNotReceive('execute');
        } else {
            $agent->shouldReceive('execute')->once()->andReturn(['execution' => null, 'output' => ['result' => $reply]]);
        }
        $this->app->instance(ExecuteAgentAction::class, $agent);
    }

    private function service(): ChatbotResponseService
    {
        return app(ChatbotResponseService::class);
    }

    public function test_hit_answers_from_cache_without_running_the_agent(): void
    {
        $bot = $this->chatbot();
        $this->storeEntry($bot, 'Колко струва доставката?', 'Безплатна над 50 лв.', $this->axis(0), ['sources' => [['chunk_id' => 'c1']]]);
        $this->agentReplies(null);

        $result = $this->service()->handle($bot, $this->newSession($bot), 'Колко струва доставката?', $bot->team_id);

        $this->assertSame('Безплатна над 50 лв.', $result['reply']);
        $this->assertFalse($result['escalated']);
        $meta = $result['message']->metadata;
        $this->assertSame('hit', $meta['answer_cache']['status']);
        $this->assertSame('exact', $meta['answer_cache']['decision']);
        $this->assertSame(800, $meta['answer_cache']['saved_tokens'], 'exact hit: no judge cost to subtract');
        $this->assertSame([['chunk_id' => 'c1']], $meta['sources']);
        Queue::assertNotPushed(StoreChatbotAnswerCacheJob::class);
    }

    public function test_eligible_miss_runs_the_agent_and_queues_the_answer(): void
    {
        $bot = $this->chatbot();
        $this->agentReplies('Доставката е безплатна над 50 лв.');

        $result = $this->service()->handle($bot, $this->newSession($bot), 'Колко струва доставката?', $bot->team_id);

        $this->assertSame('Доставката е безплатна над 50 лв.', $result['reply']);
        $this->assertSame('miss', $result['message']->metadata['answer_cache']['status']);
        $this->assertSame('none', $result['message']->metadata['answer_cache']['decision']);
        Queue::assertPushed(StoreChatbotAnswerCacheJob::class, fn ($job) => $job->chatbotId === $bot->id
            && $job->question === 'Колко струва доставката?'
            && $job->vector === $this->axis(0));
    }

    public function test_follow_up_message_skips_the_cache(): void
    {
        $bot = $this->chatbot();
        $session = $this->newSession($bot);
        ChatbotMessage::create(['session_id' => $session->id, 'chatbot_id' => $bot->id, 'team_id' => $bot->team_id, 'role' => 'user', 'content' => 'Здравейте']);
        $this->storeEntry($bot, 'а колко струва той', 'cached', $this->axis(0));
        $this->agentReplies('Зависи от модела.');

        $result = $this->service()->handle($bot, $session, 'А колко струва той?', $bot->team_id);

        $this->assertSame('Зависи от модела.', $result['reply']);
        $this->assertSame(['status' => 'skipped', 'reason' => 'follow_up'], $result['message']->metadata['answer_cache']);
        Queue::assertNotPushed(StoreChatbotAnswerCacheJob::class);
    }

    public function test_low_confidence_reply_with_fallback_is_not_queued(): void
    {
        $bot = $this->chatbot(attributes: ['confidence_threshold' => 0.95, 'fallback_message' => 'Свържете се с нас.']);
        $this->agentReplies('Може би.');

        $this->service()->handle($bot, $this->newSession($bot), 'Колко струва доставката?', $bot->team_id);

        Queue::assertNotPushed(StoreChatbotAnswerCacheJob::class);
    }

    public function test_missing_embedding_key_skips_the_cache(): void
    {
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embedForTeam')->andReturnNull();
        $this->app->instance(EmbeddingProviderInterface::class, $embedding);
        $bot = $this->chatbot();
        $this->agentReplies('Безплатна.');

        $result = $this->service()->handle($bot, $this->newSession($bot), 'Колко струва доставката?', $bot->team_id);

        $this->assertSame('no_embedding', $result['message']->metadata['answer_cache']['reason']);
        Queue::assertNotPushed(StoreChatbotAnswerCacheJob::class);
    }

    public function test_stream_hit_is_delivered_through_on_chunk(): void
    {
        $bot = $this->chatbot();
        $this->storeEntry($bot, 'Колко струва доставката?', 'Безплатна над 50 лв.', $this->axis(0));
        $chunks = [];

        $message = $this->service()->handleStream($bot, $this->newSession($bot), 'Колко струва доставката?', $bot->team_id, function (string $c) use (&$chunks) {
            $chunks[] = $c;
        });

        $this->assertSame(['Безплатна над 50 лв.'], $chunks);
        $this->assertSame('hit', $message->metadata['answer_cache']['status']);
    }

    public function test_stream_miss_queues_the_answer_with_token_usage(): void
    {
        $bot = $this->chatbot();
        $gateway = $this->app->make(AiGatewayInterface::class);
        $gateway->shouldReceive('stream')->once()->andReturnUsing(function ($request, callable $onChunk) {
            $onChunk('Безплатна над 50 лв.');

            return new AiResponseDTO('Безплатна над 50 лв.', null, new AiUsageDTO(400, 60, 9), 'openai', 'gpt-4o-mini', 10);
        });

        $this->service()->handleStream($bot, $this->newSession($bot), 'Колко струва доставката?', $bot->team_id, fn () => null);

        Queue::assertPushed(StoreChatbotAnswerCacheJob::class, fn ($job) => $job->generationTokens === 460 && $job->generationCostCredits === 9);
    }
}
