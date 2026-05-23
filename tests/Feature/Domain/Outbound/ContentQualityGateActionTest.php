<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Outbound;

use App\Domain\Outbound\Actions\ContentQualityGateAction;
use App\Domain\Outbound\Exceptions\ContentQualityException;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ContentQualityGateActionTest extends TestCase
{
    use RefreshDatabase;

    private function team(array $settings): Team
    {
        $user = User::factory()->create();

        return Team::create([
            'name' => 'CQ '.bin2hex(random_bytes(3)),
            'slug' => 'cq-'.bin2hex(random_bytes(3)),
            'owner_id' => $user->id,
            'settings' => $settings,
        ]);
    }

    private function fakeGateway(string $content): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(new AiResponseDTO(
            content: $content,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 20, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            latencyMs: 5,
        ));
        $this->app->instance(AiGatewayInterface::class, $gateway);
    }

    public function test_guard_is_noop_when_gate_disabled(): void
    {
        $team = $this->team([]);
        $proposal = OutboundProposal::factory()->for($team)->create([
            'content' => ['body' => 'synergy everywhere'],
        ]);

        $result = app(ContentQualityGateAction::class)->guard($proposal);

        $this->assertNull($result);
    }

    public function test_guard_blocks_on_brand_violation_in_block_mode(): void
    {
        $team = $this->team([
            'brand_voice' => ['forbidden_phrases' => ['synergy']],
            'content_gate' => ['enabled' => true, 'mode' => 'block'],
        ]);
        $proposal = OutboundProposal::factory()->for($team)->create([
            'content' => ['body' => 'Maximum synergy achieved.'],
        ]);

        $this->expectException(ContentQualityException::class);

        app(ContentQualityGateAction::class)->guard($proposal);
    }

    public function test_guard_warns_but_proceeds_in_warn_mode(): void
    {
        $team = $this->team([
            'brand_voice' => ['forbidden_phrases' => ['synergy']],
            'content_gate' => ['enabled' => true, 'mode' => 'warn'],
        ]);
        $proposal = OutboundProposal::factory()->for($team)->create([
            'content' => ['body' => 'Maximum synergy achieved.'],
        ]);

        $result = app(ContentQualityGateAction::class)->guard($proposal);

        $this->assertNotNull($result);
        $this->assertFalse($result->passed);
        $this->assertNotEmpty($result->brandViolations);
    }

    public function test_execute_fails_on_low_llm_score_when_llm_check_enabled(): void
    {
        $this->fakeGateway('{"score": 0.2, "issues": ["Too vague"]}');
        $team = $this->team([
            'content_gate' => ['enabled' => true, 'llm_check' => true, 'min_score' => 0.6],
        ]);

        $result = app(ContentQualityGateAction::class)->execute('Some message text.', $team->id);

        $this->assertFalse($result->passed);
        $this->assertSame(0.2, $result->score);
        $this->assertContains('Too vague', $result->qualityIssues);
    }

    public function test_execute_fails_open_when_judge_throws(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andThrow(new \RuntimeException('provider down'));
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $team = $this->team([
            'content_gate' => ['enabled' => true, 'llm_check' => true],
        ]);

        $result = app(ContentQualityGateAction::class)->execute('Clean professional message.', $team->id);

        $this->assertTrue($result->passed);
        $this->assertNull($result->score);
    }
}
