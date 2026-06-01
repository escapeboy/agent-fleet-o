<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Enums\BudgetPressureLevel;
use App\Infrastructure\AI\Enums\RequestComplexity;
use App\Infrastructure\AI\Middleware\BudgetPressureRouting;
use App\Infrastructure\AI\Services\ComplexityClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BudgetConciseDirectiveTest extends TestCase
{
    use RefreshDatabase;

    private function middleware(BudgetPressureLevel $pressure): BudgetPressureRouting
    {
        $classifier = Mockery::mock(ComplexityClassifier::class);
        $classifier->shouldReceive('classify')->andReturn(RequestComplexity::Standard);

        $costCalculator = Mockery::mock(CostCalculator::class);
        $costCalculator->shouldReceive('getBudgetPressureLevel')->andReturn($pressure);

        return new BudgetPressureRouting($classifier, $costCalculator);
    }

    private function request(): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'BASE PROMPT',
            userPrompt: 'do the thing',
            teamId: 'team-1',
        );
    }

    private function runAndCaptureSystemPrompt(BudgetPressureRouting $mw, AiRequestDTO $request): string
    {
        $captured = '';
        $mw->handle($request, function (AiRequestDTO $passed) use (&$captured) {
            $captured = $passed->systemPrompt;

            return new AiResponseDTO(
                content: 'ok',
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 1, completionTokens: 1, costCredits: 0),
                provider: $passed->provider,
                model: $passed->model,
                latencyMs: 1,
            );
        });

        return $captured;
    }

    public function test_high_pressure_injects_concise_directive(): void
    {
        config(['ai_routing.budget_pressure.concise_directive' => ['enabled' => true, 'min_level' => 'medium']]);

        $prompt = $this->runAndCaptureSystemPrompt($this->middleware(BudgetPressureLevel::High), $this->request());

        $this->assertStringContainsString('Budget-Conscious Mode', $prompt);
        $this->assertStringContainsString('BASE PROMPT', $prompt);
    }

    public function test_low_pressure_below_min_level_does_not_inject(): void
    {
        config(['ai_routing.budget_pressure.concise_directive' => ['enabled' => true, 'min_level' => 'medium']]);

        $prompt = $this->runAndCaptureSystemPrompt($this->middleware(BudgetPressureLevel::Low), $this->request());

        $this->assertStringNotContainsString('Budget-Conscious Mode', $prompt);
    }

    public function test_none_pressure_does_not_inject(): void
    {
        config(['ai_routing.budget_pressure.concise_directive' => ['enabled' => true, 'min_level' => 'medium']]);

        $prompt = $this->runAndCaptureSystemPrompt($this->middleware(BudgetPressureLevel::None), $this->request());

        $this->assertSame('BASE PROMPT', $prompt);
    }

    public function test_disabled_does_not_inject_even_under_high_pressure(): void
    {
        config(['ai_routing.budget_pressure.concise_directive' => ['enabled' => false, 'min_level' => 'medium']]);

        $prompt = $this->runAndCaptureSystemPrompt($this->middleware(BudgetPressureLevel::High), $this->request());

        $this->assertStringNotContainsString('Budget-Conscious Mode', $prompt);
    }

    public function test_min_level_low_injects_at_low_pressure(): void
    {
        config(['ai_routing.budget_pressure.concise_directive' => ['enabled' => true, 'min_level' => 'low']]);

        $prompt = $this->runAndCaptureSystemPrompt($this->middleware(BudgetPressureLevel::Low), $this->request());

        $this->assertStringContainsString('Budget-Conscious Mode', $prompt);
    }
}
