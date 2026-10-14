<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Middleware\UsageTracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageTrackingCacheColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_cached_input_tokens_and_cache_strategy(): void
    {
        $team = Team::factory()->create();

        $request = new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
            systemPrompt: 'sys',
            userPrompt: 'user',
            teamId: $team->id,
            purpose: 'test',
        );

        $response = new AiResponseDTO(
            content: 'ok',
            parsedOutput: null,
            usage: new AiUsageDTO(
                promptTokens: 1000,
                completionTokens: 200,
                costCredits: 5,
                cachedInputTokens: 800,
                cacheStrategy: '5m',
            ),
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
            latencyMs: 120,
        );

        (new UsageTracking)->handle($request, fn () => $response);

        $run = AiRun::withoutGlobalScopes()->where('team_id', $team->id)->sole();

        $this->assertSame(800, $run->cached_input_tokens);
        $this->assertSame('5m', $run->cache_strategy);
    }

    public function test_cached_input_tokens_defaults_to_zero(): void
    {
        $team = Team::factory()->create();

        $run = AiRun::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'purpose' => 'test',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
            'prompt_snapshot' => ['system' => 'sys', 'user' => 'user'],
            'raw_output' => ['text' => 'ok'],
            'input_tokens' => 1,
            'output_tokens' => 1,
            'cost_credits' => 0,
            'status' => 'completed',
        ]);

        $this->assertSame(0, (int) $run->fresh()->cached_input_tokens);
        $this->assertNull($run->fresh()->cache_strategy);
    }
}
