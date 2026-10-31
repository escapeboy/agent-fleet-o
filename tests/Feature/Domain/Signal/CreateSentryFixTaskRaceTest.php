<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Actions\CreateSentryFixTaskAction;
use App\Domain\Signal\Enums\SentryFixTaskState;
use App\Domain\Signal\Models\SentryFixTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The unique-index conflict must not poison an enclosing transaction
 * (PostgreSQL aborts it with SQLSTATE 25P02 unless a savepoint is used).
 * Also run against PostgreSQL with DB_CONNECTION=pgsql.
 */
class CreateSentryFixTaskRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_lost_insert_race_inside_a_transaction_returns_the_winner_and_keeps_the_transaction_usable(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $integration = Integration::factory()->create(['team_id' => $team->id, 'driver' => 'sentry', 'name' => 'shop', 'config' => []]);
        $attributes = ['team_id' => $team->id, 'integration_id' => $integration->id, 'sentry_issue_id' => 'issue-1', 'repository' => 'acme/shop', 'title' => 'T'];

        $winner = SentryFixTask::withoutGlobalScopes()->create(array_merge($attributes, [
            'state' => SentryFixTaskState::Queued,
            'attempts' => 0,
            'events' => [],
        ]));

        // First lookup misses (the concurrent insert had not committed yet), the retry finds it.
        $action = new class extends CreateSentryFixTaskAction
        {
            private int $calls = 0;

            protected function findLive(string $teamId, ?string $integrationId, string $sentryIssueId): ?SentryFixTask
            {
                return ++$this->calls === 1 ? null : parent::findLive($teamId, $integrationId, $sentryIssueId);
            }
        };

        [$task, $created] = DB::transaction(function () use ($action, $attributes): array {
            $result = $action->execute($attributes);
            // Would throw SQLSTATE 25P02 on PostgreSQL if the transaction had been aborted.
            $this->assertSame(1, DB::table('sentry_fix_tasks')->count());

            return $result;
        });

        $this->assertFalse($created);
        $this->assertSame($winner->id, $task->id);
    }
}
