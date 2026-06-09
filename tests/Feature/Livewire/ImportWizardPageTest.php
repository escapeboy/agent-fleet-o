<?php

namespace Tests\Feature\Livewire;

use App\Domain\Migration\Enums\MigrationStatus;
use App\Domain\Migration\Models\MigrationRun;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Livewire\Migration\ImportWizardPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ImportWizardPageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Import Team',
            'slug' => 'import-team',
            'owner_id' => $this->owner->id,
            'settings' => [],
        ]);
        $this->owner->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->owner, ['role' => 'owner']);
    }

    /**
     * Fake the AI gateway so DetectSchemaAction never hits a real provider.
     * The detector reads `parsedOutput` first — we return a clean column_map.
     *
     * @param  array<string, ?string>  $columnMap
     */
    private function fakeGateway(array $columnMap): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(new AiResponseDTO(
            content: json_encode(['column_map' => $columnMap, 'confidence' => 0.9, 'warnings' => []]),
            parsedOutput: ['column_map' => $columnMap, 'confidence' => 0.9, 'warnings' => []],
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 20, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            latencyMs: 5,
        ));
        $this->app->instance(AiGatewayInterface::class, $gateway);
    }

    private function csvFile(): UploadedFile
    {
        $csv = "Full Name,Email,Phone\nJane Doe,jane@example.com,+359100\nJohn Smith,john@example.com,+359200\n";

        return UploadedFile::fake()->createWithContent('contacts.csv', $csv);
    }

    public function test_detect_step_produces_a_mapping(): void
    {
        $this->fakeGateway([
            'Full Name' => 'display_name',
            'Email' => 'email',
            'Phone' => 'phone',
        ]);

        Livewire::actingAs($this->owner)
            ->test(ImportWizardPage::class)
            ->set('file', $this->csvFile())
            ->call('detect')
            ->assertSet('step', 2)
            ->assertSet('mapping.Email', 'email')
            ->assertSet('mapping.Full Name', 'display_name');

        $this->assertDatabaseHas('migration_runs', [
            'team_id' => $this->team->id,
            'status' => MigrationStatus::AwaitingConfirmation->value,
        ]);
    }

    public function test_import_step_executes_migration_and_creates_run(): void
    {
        $this->fakeGateway([
            'Full Name' => 'display_name',
            'Email' => 'email',
            'Phone' => 'phone',
        ]);

        Livewire::actingAs($this->owner)
            ->test(ImportWizardPage::class)
            ->set('file', $this->csvFile())
            ->call('detect')
            ->call('import')
            ->assertSet('step', 3)
            ->assertHasNoErrors();

        // Queue is sync in tests — ExecuteMigrationJob runs immediately.
        $run = MigrationRun::query()->where('team_id', $this->team->id)->firstOrFail();
        $this->assertSame(MigrationStatus::Completed, $run->status);
        $this->assertSame(2, $run->stats['created']);
        $this->assertDatabaseCount('contact_identities', 2);
    }

    public function test_unauthorized_user_is_aborted(): void
    {
        // Base edition defines `edit-content` as always-true; override it to
        // false so the per-action authorization guard is exercised directly.
        // (Cloud edition gates this on TeamRole — same code path, real deny.)
        Gate::define('edit-content', fn ($user) => false);

        $viewer = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($viewer, ['role' => 'viewer']);

        Livewire::actingAs($viewer)
            ->test(ImportWizardPage::class)
            ->assertForbidden();
    }
}
