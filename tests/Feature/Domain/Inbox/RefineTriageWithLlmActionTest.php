<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Inbox;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Inbox\Actions\RefineTriageWithLlmAction;
use App\Domain\Inbox\Models\InboxTriageResult;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class RefineTriageWithLlmActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Triage LLM Test',
            'slug' => 'triage-llm',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        Cache::flush();
        Redis::flushdb();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Cache::flush();
        Redis::flushdb();
        parent::tearDown();
    }

    private function bindGateway(?string $jsonResponse, bool $shouldThrow = false): void
    {
        $mock = Mockery::mock(AiGatewayInterface::class);

        if ($shouldThrow) {
            $mock->shouldReceive('complete')->andThrow(new \RuntimeException('LLM unavailable'));
        } else {
            $mock->shouldReceive('complete')->andReturn(new AiResponseDTO(
                content: $jsonResponse ?? '',
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 100, completionTokens: 50, costCredits: 1),
                provider: 'anthropic',
                model: 'claude-sonnet-4-5',
                latencyMs: 100,
            ));
        }

        $this->app->instance(AiGatewayInterface::class, $mock);

        $resolverMock = Mockery::mock(ProviderResolver::class);
        $resolverMock->shouldReceive('resolve')
            ->andReturn(['anthropic', 'claude-sonnet-4-5']);
        $this->app->instance(ProviderResolver::class, $resolverMock);
    }

    private function makeApproval(): ApprovalRequest
    {
        return ApprovalRequest::create([
            'team_id' => $this->team->id,
            'status' => ApprovalStatus::Pending,
            'context' => ['summary' => 'Test'],
        ]);
    }

    public function test_returns_llm_verdict_on_valid_response(): void
    {
        $this->bindGateway('{"score": 0.8, "rec": "review_now", "reason": "expired SLA"}');
        $approval = $this->makeApproval();

        $verdict = app(RefineTriageWithLlmAction::class)->execute($approval);

        $this->assertSame(0.8, $verdict->score);
        $this->assertSame('review_now', $verdict->recommendation);
        $this->assertFalse($verdict->fromCache);
    }

    public function test_persists_result_for_feedback_learning(): void
    {
        $this->bindGateway('{"score": 0.5, "rec": "review_soon", "reason": "moderate risk"}');
        $approval = $this->makeApproval();

        app(RefineTriageWithLlmAction::class)->execute($approval);

        $row = InboxTriageResult::where('source_id', $approval->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(0.5, (float) $row->llm_score);
        $this->assertSame('review_soon', $row->llm_recommendation);
    }

    public function test_caches_result_for_one_hour(): void
    {
        $this->bindGateway('{"score": 0.6, "rec": "review_soon", "reason": "first call"}');
        $approval = $this->makeApproval();

        $first = app(RefineTriageWithLlmAction::class)->execute($approval);
        $this->assertFalse($first->fromCache);

        // Bind a different response to ensure the second call doesn't hit gateway
        $this->bindGateway('{"score": 0.99, "rec": "review_now", "reason": "should not be returned"}');
        $second = app(RefineTriageWithLlmAction::class)->execute($approval);

        $this->assertTrue($second->fromCache);
        $this->assertSame(0.6, $second->score);  // From cache, not from new mock
    }

    public function test_falls_back_to_heuristic_on_invalid_json(): void
    {
        $this->bindGateway('this is not json');
        $approval = $this->makeApproval();

        $verdict = app(RefineTriageWithLlmAction::class)->execute($approval);

        $this->assertStringContainsString('[heuristic-only]', $verdict->reason);
    }

    public function test_falls_back_to_heuristic_on_gateway_exception(): void
    {
        $this->bindGateway(null, shouldThrow: true);
        $approval = $this->makeApproval();

        $verdict = app(RefineTriageWithLlmAction::class)->execute($approval);

        $this->assertStringContainsString('[heuristic-only]', $verdict->reason);
        $this->assertStringContainsString('gateway error', $verdict->reason);
    }

    public function test_cost_cap_returns_heuristic_after_100_calls(): void
    {
        // Pre-populate the daily counter to cap
        Redis::set("inbox_triage_count:{$this->team->id}:".now()->toDateString(), '100');

        $this->bindGateway('{"score": 0.9, "rec": "review_now", "reason": "ignored"}');
        $approval = $this->makeApproval();

        $verdict = app(RefineTriageWithLlmAction::class)->execute($approval);

        $this->assertStringContainsString('[heuristic-only]', $verdict->reason);
        $this->assertStringContainsString('cost cap', $verdict->reason);
    }

    public function test_strips_markdown_fences_from_llm_response(): void
    {
        $this->bindGateway("```json\n{\"score\": 0.7, \"rec\": \"review_now\", \"reason\": \"fenced\"}\n```");
        $approval = $this->makeApproval();

        $verdict = app(RefineTriageWithLlmAction::class)->execute($approval);

        $this->assertSame(0.7, $verdict->score);
        $this->assertSame('fenced', $verdict->reason);
    }

    public function test_rejects_invalid_recommendation_value(): void
    {
        $this->bindGateway('{"score": 0.5, "rec": "delete_immediately", "reason": "bad rec"}');
        $approval = $this->makeApproval();

        $verdict = app(RefineTriageWithLlmAction::class)->execute($approval);

        $this->assertStringContainsString('[heuristic-only]', $verdict->reason);
    }
}
