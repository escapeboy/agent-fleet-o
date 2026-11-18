<?php

namespace Tests\Feature\Budget;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Budget\Actions\EnforceTeamSpendCapAction;
use App\Domain\Budget\Exceptions\InsufficientBudgetException;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\NotificationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class EnforceTeamSpendCapActionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{type: string, title: string, data: array<string, mixed>|null}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->sent = [];

        $mock = Mockery::mock(NotificationService::class);
        $mock->shouldReceive('notify')->andReturnUsing(function (...$args) {
            $this->sent[] = [
                'type' => $args[2],
                'title' => $args[3],
                'data' => $args[6] ?? null,
            ];

            return null;
        });
        $this->app->instance(NotificationService::class, $mock);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function teamWith(array $settings): Team
    {
        $owner = User::factory()->create();

        return Team::factory()->create([
            'owner_id' => $owner->id,
            'settings' => $settings,
        ]);
    }

    private function spend(Team $team, int $credits, ?\DateTimeInterface $at = null): void
    {
        $run = AiRun::factory()->create([
            'team_id' => $team->id,
            'cost_credits' => $credits,
        ]);

        if ($at !== null) {
            AiRun::withoutGlobalScopes()->whereKey($run->id)->update(['created_at' => $at]);
        }
    }

    private function action(): EnforceTeamSpendCapAction
    {
        return app(EnforceTeamSpendCapAction::class);
    }

    public function test_team_without_caps_passes_even_with_high_spend(): void
    {
        $team = $this->teamWith([]);
        $this->spend($team, 1_000_000);

        $this->action()->execute($team->id, 5000);

        $this->assertSame([], $this->sent);
    }

    public function test_empty_team_id_passes(): void
    {
        $this->action()->execute('', 5000);

        $this->assertSame([], $this->sent);
    }

    public function test_daily_cap_exceeded_throws_and_names_daily_limit(): void
    {
        $team = $this->teamWith(['spend_cap_daily_credits' => 1000]);
        $this->spend($team, 990);

        try {
            $this->action()->execute($team->id, 50);
            $this->fail('Expected InsufficientBudgetException');
        } catch (InsufficientBudgetException $e) {
            $this->assertStringContainsString('daily', $e->getMessage());
            $this->assertStringContainsString('990 of 1000', $e->getMessage());
        }

        $this->assertCount(1, $this->sent);
        $this->assertSame('budget.exceeded', $this->sent[0]['type']);
        $this->assertSame('daily', $this->sent[0]['data']['period']);
    }

    public function test_spend_from_previous_day_does_not_count_toward_daily_cap(): void
    {
        $team = $this->teamWith(['spend_cap_daily_credits' => 1000]);
        $this->spend($team, 5000, now()->subDays(2));

        $this->action()->execute($team->id, 50);

        $this->assertSame([], $this->sent);
    }

    public function test_monthly_cap_exceeded_throws(): void
    {
        $team = $this->teamWith(['spend_cap_monthly_credits' => 10_000]);
        $this->spend($team, 9_990);

        $this->expectException(InsufficientBudgetException::class);
        $this->expectExceptionMessage('monthly');

        $this->action()->execute($team->id, 50);
    }

    public function test_other_team_spend_is_not_counted(): void
    {
        $team = $this->teamWith(['spend_cap_daily_credits' => 1000]);
        $other = $this->teamWith([]);
        $this->spend($other, 5000);

        $this->action()->execute($team->id, 50);

        $this->assertSame([], $this->sent);
    }

    public function test_warning_at_80_percent_is_sent_once_per_period(): void
    {
        $team = $this->teamWith(['spend_cap_daily_credits' => 1000]);
        $this->spend($team, 800);

        $this->action()->execute($team->id, 10);
        $this->action()->execute($team->id, 10);

        $this->assertCount(1, $this->sent);
        $this->assertSame('usage.alert', $this->sent[0]['type']);
        $this->assertSame('Team spend at 80% of limit', $this->sent[0]['title']);
    }

    public function test_block_notice_is_sent_once_per_period(): void
    {
        $team = $this->teamWith(['spend_cap_daily_credits' => 1000]);
        $this->spend($team, 1000);

        foreach ([1, 2] as $_) {
            try {
                $this->action()->execute($team->id, 10);
                $this->fail('Expected InsufficientBudgetException');
            } catch (InsufficientBudgetException) {
                // expected
            }
        }

        $this->assertCount(1, $this->sent);
        $this->assertSame('budget.exceeded', $this->sent[0]['type']);
    }

    public function test_below_80_percent_sends_nothing(): void
    {
        $team = $this->teamWith(['spend_cap_daily_credits' => 1000]);
        $this->spend($team, 100);

        $this->action()->execute($team->id, 10);

        $this->assertSame([], $this->sent);
    }

    public function test_failing_notification_does_not_block_call(): void
    {
        $mock = Mockery::mock(NotificationService::class);
        $mock->shouldReceive('notify')->andThrow(new \RuntimeException('mail down'));
        $this->app->instance(NotificationService::class, $mock);

        $team = $this->teamWith(['spend_cap_daily_credits' => 1000]);
        $this->spend($team, 850);

        $this->action()->execute($team->id, 10);

        $this->expectException(InsufficientBudgetException::class);
        $this->action()->execute($team->id, 500);
    }
}
