<?php

namespace Tests\Feature\Domain\Chatbot;

use App\Domain\Chatbot\Services\ChatbotMemoryContextProvider;
use App\Domain\Memory\Actions\ClassifyQueryTopicAction;
use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ChatbotMemoryContextProviderTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
    }

    private function makeMemory(string $content, string $tier = 'facts'): object
    {
        return (object) ['content' => $content, 'tier' => $tier];
    }

    private function makeProvider(
        RetrieveRelevantMemoriesAction $retrieve,
        ClassifyQueryTopicAction $classify,
    ): ChatbotMemoryContextProvider {
        return new ChatbotMemoryContextProvider($retrieve, $classify);
    }

    // ─── Flag OFF (default) ────────────────────────────────────────────────────

    public function test_flag_off_does_not_call_classifier_and_passes_null_topic(): void
    {
        config(['chat.memory.topic_filter_enabled' => false]);

        $classify = Mockery::mock(ClassifyQueryTopicAction::class);
        $classify->shouldNotReceive('execute');

        $capturedTopic = 'NOT_CAPTURED';
        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $retrieve->shouldReceive('execute')
            ->once()
            ->andReturnUsing(function () use (&$capturedTopic) {
                $args = func_get_args();
                $capturedTopic = $args[9] ?? null;

                return collect([$this->makeMemory('Some fact')]);
            });

        $provider = $this->makeProvider($retrieve, $classify);
        $result = $provider->retrieveContext('How do I reset my password?', 'user');

        $this->assertNull($capturedTopic);
        $this->assertStringContainsString('Some fact', $result);
    }

    // ─── Flag ON — classifier returns a slug ───────────────────────────────────

    public function test_flag_on_classifier_slug_is_forwarded_to_retrieval(): void
    {
        config([
            'chat.memory.topic_filter_enabled' => true,
            'chat.memory.topic_filter_fallback_on_empty' => false,
        ]);

        $classify = Mockery::mock(ClassifyQueryTopicAction::class);
        $classify->shouldReceive('execute')->once()->andReturn('auth_migration');

        $capturedTopic = 'NOT_CAPTURED';
        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $retrieve->shouldReceive('execute')
            ->once()
            ->andReturnUsing(function () use (&$capturedTopic) {
                $args = func_get_args();
                $capturedTopic = $args[9] ?? null;

                return collect([$this->makeMemory('Auth fact')]);
            });

        $provider = $this->makeProvider($retrieve, $classify);
        $result = $provider->retrieveContext('Tell me about auth migration', 'user');

        $this->assertSame('auth_migration', $capturedTopic);
        $this->assertStringContainsString('Auth fact', $result);
    }

    // ─── Flag ON — classifier returns null ─────────────────────────────────────

    public function test_flag_on_classifier_null_passes_null_topic_to_retrieval(): void
    {
        config(['chat.memory.topic_filter_enabled' => true]);

        $classify = Mockery::mock(ClassifyQueryTopicAction::class);
        $classify->shouldReceive('execute')->once()->andReturn(null);

        $capturedTopic = 'NOT_CAPTURED';
        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $retrieve->shouldReceive('execute')
            ->once()
            ->andReturnUsing(function () use (&$capturedTopic) {
                $args = func_get_args();
                $capturedTopic = $args[9] ?? null;

                return collect([$this->makeMemory('Generic fact')]);
            });

        $provider = $this->makeProvider($retrieve, $classify);
        $result = $provider->retrieveContext('What is the meaning of life?', 'user');

        $this->assertNull($capturedTopic);
        $this->assertStringContainsString('Generic fact', $result);
    }

    // ─── Flag ON — empty result + fallback ON ──────────────────────────────────

    public function test_flag_on_empty_result_triggers_fallback_when_fallback_enabled(): void
    {
        config([
            'chat.memory.topic_filter_enabled' => true,
            'chat.memory.topic_filter_fallback_on_empty' => true,
        ]);

        $classify = Mockery::mock(ClassifyQueryTopicAction::class);
        $classify->shouldReceive('execute')->once()->andReturn('checkout_flow');

        $callCount = 0;
        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $retrieve->shouldReceive('execute')
            ->twice()
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;

                return $callCount === 1
                    ? collect()
                    : collect([$this->makeMemory('Fallback fact')]);
            });

        $provider = $this->makeProvider($retrieve, $classify);
        $result = $provider->retrieveContext('How do I checkout?', 'user');

        $this->assertSame(2, $callCount);
        $this->assertStringContainsString('Fallback fact', $result);
    }

    // ─── Flag ON — empty result + fallback OFF ─────────────────────────────────

    public function test_flag_on_empty_result_no_second_call_when_fallback_disabled(): void
    {
        config([
            'chat.memory.topic_filter_enabled' => true,
            'chat.memory.topic_filter_fallback_on_empty' => false,
        ]);

        $classify = Mockery::mock(ClassifyQueryTopicAction::class);
        $classify->shouldReceive('execute')->once()->andReturn('checkout_flow');

        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $retrieve->shouldReceive('execute')->once()->andReturn(collect());

        $provider = $this->makeProvider($retrieve, $classify);
        $result = $provider->retrieveContext('How do I checkout?', 'user');

        $this->assertNull($result);
    }

    // ─── Classifier throws — retrieval proceeds with null topic ───────────────

    public function test_classifier_exception_does_not_propagate_and_topic_is_null(): void
    {
        config(['chat.memory.topic_filter_enabled' => true]);

        $classify = Mockery::mock(ClassifyQueryTopicAction::class);
        $classify->shouldReceive('execute')
            ->once()
            ->andThrow(new \RuntimeException('LLM error'));

        $capturedTopic = 'NOT_CAPTURED';
        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $retrieve->shouldReceive('execute')
            ->once()
            ->andReturnUsing(function () use (&$capturedTopic) {
                $args = func_get_args();
                $capturedTopic = $args[9] ?? null;

                return collect([$this->makeMemory('Safe fact')]);
            });

        $provider = $this->makeProvider($retrieve, $classify);
        $result = $provider->retrieveContext('Something went wrong', 'user');

        $this->assertNull($capturedTopic);
        $this->assertStringContainsString('Safe fact', $result);
    }

    // ─── Memory config disabled ────────────────────────────────────────────────

    public function test_returns_null_when_memory_disabled(): void
    {
        config(['memory.enabled' => false]);

        $classify = Mockery::mock(ClassifyQueryTopicAction::class);
        $classify->shouldNotReceive('execute');

        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $retrieve->shouldNotReceive('execute');

        $provider = $this->makeProvider($retrieve, $classify);
        $result = $provider->retrieveContext('any query', 'user');

        $this->assertNull($result);
    }
}
