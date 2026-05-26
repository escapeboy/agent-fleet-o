<?php

namespace Tests\Unit\Domain\Approval;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Services\ApprovalSummarizer;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Mockery;
use Tests\TestCase;

class ApprovalSummarizerTest extends TestCase
{
    private function makeApproval(): ApprovalRequest
    {
        $approval = new ApprovalRequest;
        $approval->id = 'test-approval-id';
        $approval->team_id = null; // Team::ownerIdFor(null) short-circuits, no DB hit
        $approval->context = [
            'experiment_title' => 'Send weekly digest',
            'experiment_thesis' => 'Notify 5000 users.',
        ];

        return $approval;
    }

    private function makeResponse(string $content): AiResponseDTO
    {
        return new AiResponseDTO(
            content: $content,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 20, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            latencyMs: 0,
        );
    }

    public function test_happy_path_returns_summary_risk_rationale(): void
    {
        $json = json_encode([
            'summary' => 'Sends a digest email to 5000 recipients.',
            'risk' => 'high',
            'rationale' => 'Broad outbound blast radius.',
        ]);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->expects('complete')->once()->andReturn($this->makeResponse($json));

        $result = (new ApprovalSummarizer($gateway))->summarize($this->makeApproval());

        $this->assertSame('high', $result['risk']);
        $this->assertNotEmpty($result['summary']);
        $this->assertNotEmpty($result['rationale']);
    }

    public function test_invalid_risk_value_normalizes_to_medium(): void
    {
        $json = json_encode([
            'summary' => 'Does a thing.',
            'risk' => 'catastrophic',
            'rationale' => 'n/a',
        ]);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->expects('complete')->once()->andReturn($this->makeResponse($json));

        $result = (new ApprovalSummarizer($gateway))->summarize($this->makeApproval());

        $this->assertSame('medium', $result['risk']);
    }

    public function test_fenced_json_with_surrounding_prose_is_parsed(): void
    {
        $raw = "Here you go:\n```json\n".json_encode([
            'summary' => 'Activates a credential.',
            'risk' => 'medium',
            'rationale' => 'Reversible.',
        ])."\n```\nHope that helps.";

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->expects('complete')->once()->andReturn($this->makeResponse($raw));

        $result = (new ApprovalSummarizer($gateway))->summarize($this->makeApproval());

        $this->assertSame('Activates a credential.', $result['summary']);
        $this->assertSame('medium', $result['risk']);
    }

    public function test_malformed_output_throws_runtime_exception(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->expects('complete')->once()->andReturn($this->makeResponse('totally not json'));

        $this->expectException(\RuntimeException::class);

        (new ApprovalSummarizer($gateway))->summarize($this->makeApproval());
    }
}
