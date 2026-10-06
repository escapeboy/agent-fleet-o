<?php

namespace Tests\Feature\Domain\Chatbot\AnswerCache;

use App\Domain\Agent\Models\Agent;
use App\Domain\Chatbot\Enums\ChatbotType;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotAnswerCacheEntry;
use App\Domain\Chatbot\Services\ChatbotAnswerCache;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

abstract class AnswerCacheTestCase extends TestCase
{
    use RefreshDatabase;

    /** @var list<AiRequestDTO> */
    protected array $gatewayRequests = [];

    /** @var list<string|\Throwable> */
    protected array $gatewayReplies = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['chatbot_answer_cache.enabled' => true]);

        $resolver = Mockery::mock(ProviderResolver::class);
        $resolver->shouldReceive('resolveInternal')->andReturn(['provider' => 'openai', 'model' => 'gpt-4o-mini']);
        $this->app->instance(ProviderResolver::class, $resolver);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturnUsing(function (AiRequestDTO $request) {
            $this->gatewayRequests[] = $request;
            $reply = array_shift($this->gatewayReplies) ?? '{"decision":"reject","use":[]}';
            if ($reply instanceof \Throwable) {
                throw $reply;
            }

            return new AiResponseDTO(
                content: $reply,
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 100, completionTokens: 20, costCredits: 1),
                provider: 'openai',
                model: 'gpt-4o-mini',
                latencyMs: 5,
            );
        });
        $this->app->instance(AiGatewayInterface::class, $gateway);
    }

    protected function cache(): ChatbotAnswerCache
    {
        return app(ChatbotAnswerCache::class);
    }

    protected function team(): Team
    {
        $user = User::factory()->create();

        return Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
    }

    protected function chatbot(?Team $team = null, bool $enabled = true, array $attributes = []): Chatbot
    {
        $team ??= $this->team();
        $agent = Agent::factory()->create(['team_id' => $team->id]);

        return Chatbot::create(array_merge([
            'team_id' => $team->id,
            'agent_id' => $agent->id,
            'name' => 'Help',
            'slug' => 'help-'.bin2hex(random_bytes(3)),
            'type' => ChatbotType::HelpBot,
            'confidence_threshold' => 0.5,
            'config' => ['answer_cache' => ['enabled' => $enabled]],
        ], $attributes))->refresh();
    }

    /**
     * @param  float[]  $vector
     */
    protected function storeEntry(Chatbot $chatbot, string $question, string $answer, array $vector, array $extra = []): ChatbotAnswerCacheEntry
    {
        return $this->cache()->store(
            chatbot: $chatbot,
            generation: $extra['generation'] ?? (int) $chatbot->answer_cache_generation,
            promptHash: $extra['prompt_hash'] ?? $this->cache()->promptHash($chatbot),
            question: $question,
            vector: $vector,
            answer: $answer,
            sources: $extra['sources'] ?? null,
            confidence: $extra['confidence'] ?? 0.9,
            generationTokens: $extra['tokens'] ?? 800,
            generationCostCredits: $extra['credits'] ?? 12,
        );
    }

    /** A unit vector along one axis, padded to 8 dims (distance between axes = 1). */
    protected function axis(int $i, float $tilt = 0.0): array
    {
        $v = array_fill(0, 8, 0.0);
        $v[$i] = 1.0;
        $v[($i + 1) % 8] = $tilt;

        return $v;
    }
}
