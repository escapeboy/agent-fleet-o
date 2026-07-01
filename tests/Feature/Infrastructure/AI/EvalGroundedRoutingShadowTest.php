<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Middleware\EvalGroundedRoutingShadow;
use App\Infrastructure\AI\Services\EvalGroundedModelRecommender;
use App\Infrastructure\AI\Services\EvalShadowCounters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EvalGroundedRoutingShadowTest extends TestCase
{
    use RefreshDatabase;

    private function request(string $provider = 'anthropic', string $model = 'claude-opus-4-6'): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: 'sys',
            userPrompt: 'usr',
            teamId: 'team-1',
            purpose: 'scoring',
        );
    }

    private function next(): \Closure
    {
        return fn (AiRequestDTO $passed) => new AiResponseDTO(
            content: 'ok',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 1, completionTokens: 1, costCredits: 0),
            provider: $passed->provider,
            model: $passed->model,
            latencyMs: 1,
        );
    }

    public function test_passes_request_through_unchanged_when_recommendation_exists(): void
    {
        config()->set('ai_routing.eval_grounded.enabled', true);

        $recommender = Mockery::mock(EvalGroundedModelRecommender::class);
        $recommender->shouldReceive('recommend')->once()->andReturn([
            'would_downgrade' => true,
            'est_savings_per_call' => 12,
            'recommended_model' => 'anthropic/claude-haiku-4-5',
        ]);

        $counters = Mockery::mock(EvalShadowCounters::class);
        $counters->shouldReceive('record')->once()->with('team-1', Mockery::type('array'));

        $mw = new EvalGroundedRoutingShadow($recommender, $counters);

        $captured = null;
        $mw->handle($this->request(), function (AiRequestDTO $passed) use (&$captured) {
            $captured = $passed;

            return $this->next()($passed);
        });

        $this->assertSame('anthropic', $captured->provider);
        $this->assertSame('claude-opus-4-6', $captured->model);
        $this->assertSame('scoring', $captured->purpose);
    }

    public function test_no_record_when_recommender_returns_null(): void
    {
        config()->set('ai_routing.eval_grounded.enabled', true);

        $recommender = Mockery::mock(EvalGroundedModelRecommender::class);
        $recommender->shouldReceive('recommend')->once()->andReturnNull();

        $counters = Mockery::mock(EvalShadowCounters::class);
        $counters->shouldNotReceive('record');

        $mw = new EvalGroundedRoutingShadow($recommender, $counters);
        $response = $mw->handle($this->request(), $this->next());

        $this->assertSame('ok', $response->content);
    }

    public function test_recommender_not_called_when_flag_off(): void
    {
        config()->set('ai_routing.eval_grounded.enabled', false);

        $recommender = Mockery::mock(EvalGroundedModelRecommender::class);
        $recommender->shouldNotReceive('recommend');

        $counters = Mockery::mock(EvalShadowCounters::class);

        $mw = new EvalGroundedRoutingShadow($recommender, $counters);
        $response = $mw->handle($this->request(), $this->next());

        $this->assertSame('ok', $response->content);
    }

    public function test_swallows_recommender_failure_and_still_forwards(): void
    {
        config()->set('ai_routing.eval_grounded.enabled', true);

        $recommender = Mockery::mock(EvalGroundedModelRecommender::class);
        $recommender->shouldReceive('recommend')->once()->andThrow(new \RuntimeException('boom'));

        $counters = Mockery::mock(EvalShadowCounters::class);
        $counters->shouldNotReceive('record');

        $mw = new EvalGroundedRoutingShadow($recommender, $counters);
        $response = $mw->handle($this->request(), $this->next());

        $this->assertSame('ok', $response->content);
    }

    public function test_does_not_alter_routing_for_local_provider_archetypes(): void
    {
        config()->set('ai_routing.eval_grounded.enabled', true);

        $recommender = Mockery::mock(EvalGroundedModelRecommender::class);
        $recommender->shouldReceive('recommend')->andReturn([
            'would_downgrade' => true,
            'est_savings_per_call' => 5,
            'recommended_model' => 'anthropic/claude-haiku-4-5',
        ]);
        $counters = Mockery::mock(EvalShadowCounters::class);
        $counters->shouldReceive('record');

        $mw = new EvalGroundedRoutingShadow($recommender, $counters);

        foreach (['claude-code-vps', 'bridge_agent', 'codex'] as $provider) {
            $captured = null;
            $mw->handle($this->request(provider: $provider, model: 'claude-sonnet-4-5'), function (AiRequestDTO $passed) use (&$captured) {
                $captured = $passed;

                return $this->next()($passed);
            });

            $this->assertSame($provider, $captured->provider);
            $this->assertSame('claude-sonnet-4-5', $captured->model);
        }
    }
}
