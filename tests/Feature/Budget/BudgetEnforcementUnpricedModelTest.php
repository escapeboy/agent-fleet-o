<?php

namespace Tests\Feature\Budget;

use App\Domain\Budget\Exceptions\InsufficientBudgetException;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\NotificationService;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Middleware\BudgetEnforcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class BudgetEnforcementUnpricedModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $mock = Mockery::mock(NotificationService::class);
        $mock->shouldReceive('notify')->andReturnNull();
        $this->app->instance(NotificationService::class, $mock);
    }

    private function cappedTeam(): Team
    {
        $owner = User::factory()->create();

        return Team::factory()->create([
            'owner_id' => $owner->id,
            'settings' => ['spend_cap_daily_credits' => 1000],
        ]);
    }

    private function request(Team $team, string $provider, string $model): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: 'system',
            userPrompt: 'hello',
            maxTokens: 100,
            teamId: $team->id,
        );
    }

    private function response(string $provider, string $model): AiResponseDTO
    {
        return new AiResponseDTO(
            content: 'ok',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 1, completionTokens: 1, costCredits: 0),
            provider: $provider,
            model: $model,
            latencyMs: 1,
        );
    }

    public function test_unpriced_model_is_blocked_before_the_call(): void
    {
        $team = $this->cappedTeam();
        $called = false;

        try {
            app(BudgetEnforcement::class)->handle(
                $this->request($team, 'openai_compatible', 'some-new-model'),
                function () use (&$called) {
                    $called = true;

                    return $this->response('openai_compatible', 'some-new-model');
                },
            );
            $this->fail('Expected InsufficientBudgetException');
        } catch (InsufficientBudgetException $e) {
            $this->assertStringContainsString('openai_compatible/some-new-model', $e->getMessage());
        }

        $this->assertFalse($called);
    }

    public function test_priced_model_passes_through(): void
    {
        $team = $this->cappedTeam();
        $called = false;

        $response = app(BudgetEnforcement::class)->handle(
            $this->request($team, 'anthropic', 'claude-sonnet-4-5'),
            function () use (&$called) {
                $called = true;

                return $this->response('anthropic', 'claude-sonnet-4-5');
            },
        );

        $this->assertTrue($called);
        $this->assertSame('ok', $response->content);
    }
}
