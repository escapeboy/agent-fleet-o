<?php

namespace Tests\Feature\Domain\Crew;

use App\Domain\Crew\Actions\GenerateCrewFromPromptAction;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateCrewFromPromptTest extends TestCase
{
    use RefreshDatabase;

    private function makeValidCrewJson(): string
    {
        return json_encode([
            'crew_name' => 'Content Research Crew',
            'description' => 'A crew that researches and produces high-quality content',
            'process_type' => 'hierarchical',
            'coordinator' => [
                'role' => 'Content Director',
                'goal' => 'Orchestrate research and synthesis',
                'backstory' => 'Experienced content strategist',
                'skills' => ['content_strategy', 'team_coordination'],
            ],
            'qa_agent' => [
                'role' => 'Quality Reviewer',
                'goal' => 'Validate accuracy and quality',
                'backstory' => 'Expert fact-checker and editor',
                'skills' => ['fact_checking', 'editing'],
            ],
            'workers' => [
                [
                    'role' => 'Research Analyst',
                    'goal' => 'Gather and analyze information',
                    'backstory' => 'Expert researcher',
                    'skills' => ['web_research', 'data_analysis'],
                    'context_scope' => ['goal', 'prior_outputs'],
                ],
                [
                    'role' => 'Content Writer',
                    'goal' => 'Produce engaging written content',
                    'backstory' => 'Experienced writer',
                    'skills' => ['content_writing', 'seo'],
                    'context_scope' => ['goal', 'prior_outputs'],
                ],
            ],
            'suggested_quality_threshold' => 0.8,
            'reasoning' => 'Hierarchical process suits content production workflows',
        ]);
    }

    private function makeAction(string $responseContent): GenerateCrewFromPromptAction
    {
        $gateway = $this->createMock(AiGatewayInterface::class);
        $gateway->method('complete')->willReturn(new AiResponseDTO(
            content: $responseContent,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 100, completionTokens: 200, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            latencyMs: 100,
        ));

        $providerResolver = $this->createMock(ProviderResolver::class);
        $providerResolver->method('resolve')->willReturn([
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001',
        ]);

        return new GenerateCrewFromPromptAction($gateway, $providerResolver);
    }

    public function test_returns_structured_array_with_required_keys(): void
    {
        $action = $this->makeAction($this->makeValidCrewJson());

        $result = $action->execute('Build a content research and writing crew');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('crew_name', $result);
        $this->assertArrayHasKey('process_type', $result);
        $this->assertArrayHasKey('coordinator', $result);
        $this->assertArrayHasKey('qa_agent', $result);
        $this->assertArrayHasKey('workers', $result);
        $this->assertArrayHasKey('suggested_quality_threshold', $result);
        $this->assertArrayHasKey('reasoning', $result);
    }

    public function test_coordinator_has_expected_structure(): void
    {
        $action = $this->makeAction($this->makeValidCrewJson());

        $result = $action->execute('Build a content research and writing crew');

        $this->assertArrayHasKey('role', $result['coordinator']);
        $this->assertArrayHasKey('goal', $result['coordinator']);
        $this->assertArrayHasKey('skills', $result['coordinator']);
    }

    public function test_workers_is_non_empty_array(): void
    {
        $action = $this->makeAction($this->makeValidCrewJson());

        $result = $action->execute('Build a content research and writing crew');

        $this->assertIsArray($result['workers']);
        $this->assertNotEmpty($result['workers']);
    }

    public function test_strips_markdown_code_fences_from_response(): void
    {
        $json = $this->makeValidCrewJson();
        $action = $this->makeAction("```json\n{$json}\n```");

        $result = $action->execute('Build a crew');

        $this->assertEquals('Content Research Crew', $result['crew_name']);
    }

    public function test_throws_when_llm_returns_invalid_json(): void
    {
        $action = $this->makeAction('not valid json at all');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse LLM response into crew structure.');

        $action->execute('Build a crew');
    }

    public function test_throws_when_required_key_missing(): void
    {
        $partial = json_encode([
            'crew_name' => 'Test Crew',
            'process_type' => 'sequential',
            // missing coordinator, qa_agent, workers
        ]);

        $action = $this->makeAction($partial);

        $this->expectException(\RuntimeException::class);

        $action->execute('Build a crew');
    }

    public function test_returns_correct_process_type(): void
    {
        $action = $this->makeAction($this->makeValidCrewJson());

        $result = $action->execute('Research crew');

        $this->assertEquals('hierarchical', $result['process_type']);
    }

    public function test_quality_threshold_is_numeric(): void
    {
        $action = $this->makeAction($this->makeValidCrewJson());

        $result = $action->execute('Research crew');

        $this->assertIsNumeric($result['suggested_quality_threshold']);
        $this->assertGreaterThanOrEqual(0.0, $result['suggested_quality_threshold']);
        $this->assertLessThanOrEqual(1.0, $result['suggested_quality_threshold']);
    }
}
