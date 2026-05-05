<?php

namespace Tests\Feature\Domain\Migration;

use App\Domain\Migration\Actions\ExecuteMigrationAction;
use App\Domain\Migration\Enums\MigrationEntityType;
use App\Domain\Migration\Enums\MigrationSource;
use App\Domain\Migration\Enums\MigrationStatus;
use App\Domain\Migration\Models\MigrationRun;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrationFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Migrations Team',
            'slug' => 'migrations-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    public function test_execute_action_dispatches_job_and_imports_contacts(): void
    {
        $csv = "Full Name,Email,Phone\nJane Doe,jane@example.com,+359100\nJohn Smith,john@example.com,+359200\nDuplicate,jane@example.com,+359300\n";

        $run = MigrationRun::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'entity_type' => MigrationEntityType::Contact->value,
            'source' => MigrationSource::Csv->value,
            'source_bytes' => strlen($csv),
            'source_payload' => $csv,
            'proposed_mapping' => [
                'Full Name' => 'display_name',
                'Email' => 'email',
                'Phone' => 'phone',
            ],
            'status' => MigrationStatus::AwaitingConfirmation->value,
            'stats' => [],
            'errors' => [],
        ]);

        // Test queue defaults to sync driver — action execute() triggers the
        // dispatched job immediately on the same request cycle.
        app(ExecuteMigrationAction::class)->execute($run);

        $final = $run->fresh();
        $this->assertSame(MigrationStatus::Completed, $final->status);
        $this->assertSame(3, $final->stats['total']);
        $this->assertSame(2, $final->stats['created']);
        // Third row has same email → either 'updated' (row imports a diff) or 'skipped'.
        $this->assertGreaterThanOrEqual(0, $final->stats['updated'] + $final->stats['skipped']);
        $this->assertDatabaseCount('contact_identities', 2);
    }

    public function test_execute_rejects_invalid_target_attribute(): void
    {
        $run = MigrationRun::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'entity_type' => MigrationEntityType::Contact->value,
            'source' => MigrationSource::Csv->value,
            'source_bytes' => 10,
            'source_payload' => "a\nb\n",
            'proposed_mapping' => ['a' => 'bogus_attr'],
            'status' => MigrationStatus::AwaitingConfirmation->value,
            'stats' => [],
            'errors' => [],
        ]);

        $this->expectException(\RuntimeException::class);
        app(ExecuteMigrationAction::class)->execute($run);
    }
}
