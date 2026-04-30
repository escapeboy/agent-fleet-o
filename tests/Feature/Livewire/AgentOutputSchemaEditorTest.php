<?php

namespace Tests\Feature\Livewire;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Livewire\Agents\AgentDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AgentOutputSchemaEditorTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'T',
            'slug' => 't-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);
        $this->agent = Agent::factory()->create(['team_id' => $team->id]);
    }

    public function test_mount_prefills_existing_schema(): void
    {
        $this->agent->update([
            'output_schema' => ['type' => 'object', 'properties' => ['summary' => ['type' => 'string']]],
            'output_schema_max_retries' => 3,
        ]);

        $component = Livewire::test(AgentDetailPage::class, ['agent' => $this->agent->fresh()]);
        $this->assertStringContainsString('"type"', $component->get('editOutputSchemaJson'));
        $this->assertSame(3, $component->get('editOutputSchemaMaxRetries'));
    }

    public function test_save_valid_json_persists_as_array(): void
    {
        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->set('editOutputSchemaJson', '{"type":"object","properties":{"x":{"type":"string"}}}')
            ->set('editOutputSchemaMaxRetries', 2)
            ->call('saveOutputSchema')
            ->assertHasNoErrors()
            ->assertSet('outputSchemaSaveMessage', 'Output schema saved.');

        $fresh = $this->agent->fresh();
        $this->assertIsArray($fresh->output_schema);
        $this->assertSame('object', $fresh->output_schema['type']);
        $this->assertSame(2, $fresh->output_schema_max_retries);
    }

    public function test_invalid_json_adds_validation_error(): void
    {
        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->set('editOutputSchemaJson', '{broken json')
            ->call('saveOutputSchema')
            ->assertHasErrors(['editOutputSchemaJson']);

        $this->assertNull($this->agent->fresh()->output_schema);
    }

    public function test_non_object_schema_rejected(): void
    {
        // JSON array, not object
        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->set('editOutputSchemaJson', '[1,2,3]')
            ->call('saveOutputSchema')
            ->assertHasErrors(['editOutputSchemaJson']);
    }

    public function test_empty_json_clears_schema(): void
    {
        $this->agent->update([
            'output_schema' => ['type' => 'object'],
            'output_schema_max_retries' => 3,
        ]);

        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent->fresh()])
            ->set('editOutputSchemaJson', '')
            ->call('saveOutputSchema')
            ->assertSet('outputSchemaSaveMessage', 'Schema cleared.');

        $fresh = $this->agent->fresh();
        $this->assertNull($fresh->output_schema);
        $this->assertNull($fresh->output_schema_max_retries);
    }

    public function test_retries_out_of_range_rejected(): void
    {
        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->set('editOutputSchemaJson', '{"type":"object"}')
            ->set('editOutputSchemaMaxRetries', 99)
            ->call('saveOutputSchema')
            ->assertHasErrors(['editOutputSchemaMaxRetries']);
    }

    public function test_clear_output_schema_button_clears_both_fields(): void
    {
        $this->agent->update([
            'output_schema' => ['type' => 'object'],
            'output_schema_max_retries' => 1,
        ]);

        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent->fresh()])
            ->call('clearOutputSchema')
            ->assertSet('editOutputSchemaJson', '')
            ->assertSet('editOutputSchemaMaxRetries', null);

        $fresh = $this->agent->fresh();
        $this->assertNull($fresh->output_schema);
        $this->assertNull($fresh->output_schema_max_retries);
    }
}
