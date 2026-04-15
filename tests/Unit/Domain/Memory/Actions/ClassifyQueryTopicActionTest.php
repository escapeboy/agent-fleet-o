<?php

namespace Tests\Unit\Domain\Memory\Actions;

use App\Domain\Memory\Actions\ClassifyQueryTopicAction;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ClassifyQueryTopicActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeResponse(string $content): AiResponseDTO
    {
        return new AiResponseDTO(
            content: $content,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 5, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            latencyMs: 50,
        );
    }

    public function test_returns_null_for_empty_query(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldNotReceive('complete');

        $action = new ClassifyQueryTopicAction($gateway);

        $this->assertNull($action->execute(''));
        $this->assertNull($action->execute('   '));
    }

    public function test_returns_normalised_snake_case_slug_on_success(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andReturn($this->makeResponse('{"topic": "Auth Migration"}'));

        $action = new ClassifyQueryTopicAction($gateway);
        $result = $action->execute('How does login work?');

        $this->assertSame('auth_migration', $result);
    }

    public function test_returns_null_when_gateway_throws(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andThrow(new \RuntimeException('API down'));

        $action = new ClassifyQueryTopicAction($gateway);
        $result = $action->execute('some query');

        $this->assertNull($result);
    }

    public function test_returns_null_when_response_is_not_json(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andReturn($this->makeResponse('not json'));

        $action = new ClassifyQueryTopicAction($gateway);
        $result = $action->execute('some query');

        $this->assertNull($result);
    }

    public function test_returns_null_when_topic_field_is_null(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andReturn($this->makeResponse('{"topic": null}'));

        $action = new ClassifyQueryTopicAction($gateway);
        $result = $action->execute('vague question?');

        $this->assertNull($result);
    }

    public function test_caches_result_and_calls_gateway_only_once_for_same_query(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andReturn($this->makeResponse('{"topic": "checkout_flow"}'));

        $action = new ClassifyQueryTopicAction($gateway);

        $first = $action->execute('How do I pay?');
        $second = $action->execute('How do I pay?');

        $this->assertSame('checkout_flow', $first);
        $this->assertSame('checkout_flow', $second);
    }

    public function test_cache_key_is_case_insensitive(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andReturn($this->makeResponse('{"topic": "login_flow"}'));

        $action = new ClassifyQueryTopicAction($gateway);

        $lower = $action->execute('login');
        $upper = $action->execute('Login');

        $this->assertSame('login_flow', $lower);
        $this->assertSame('login_flow', $upper);
    }

    public function test_slug_is_capped_at_50_chars(): void
    {
        $longSlug = str_repeat('a', 60);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andReturn($this->makeResponse('{"topic": "'.$longSlug.'"}'));

        $action = new ClassifyQueryTopicAction($gateway);
        $result = $action->execute('some query about a very long topic');

        $this->assertNotNull($result);
        $this->assertLessThanOrEqual(50, mb_strlen($result));
    }
}
