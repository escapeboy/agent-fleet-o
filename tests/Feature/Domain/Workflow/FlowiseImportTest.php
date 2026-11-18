<?php

namespace Tests\Feature\Domain\Workflow;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Actions\ImportWorkflowAction;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowiseImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-flowise',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    private function agentflowJson(): string
    {
        return (string) json_encode([
            'nodes' => [
                ['id' => 'startAgentflow_0', 'position' => ['x' => 0, 'y' => 0], 'data' => ['name' => 'startAgentflow', 'label' => 'Start', 'inputs' => []]],
                ['id' => 'llmAgentflow_0', 'position' => ['x' => 200, 'y' => 0], 'data' => ['name' => 'llmAgentflow', 'label' => 'Answer', 'inputs' => [
                    'llmModel' => 'chatAnthropic',
                    'llmModelConfig' => ['modelName' => 'claude-haiku-4-5'],
                    'llmMessages' => [['role' => 'system', 'content' => 'Be brief.']],
                    'llmUserMessage' => '{{question}}',
                ]]],
                ['id' => 'directReplyAgentflow_0', 'position' => ['x' => 400, 'y' => 0], 'data' => ['name' => 'directReplyAgentflow', 'label' => 'Reply', 'inputs' => [
                    'directReplyMessage' => 'Done: {{llmAgentflow_0}}',
                ]]],
            ],
            'edges' => [
                ['source' => 'startAgentflow_0', 'target' => 'llmAgentflow_0'],
                ['source' => 'llmAgentflow_0', 'target' => 'directReplyAgentflow_0'],
            ],
        ]);
    }

    public function test_imports_agentflow_v2_as_draft_workflow(): void
    {
        $result = app(ImportWorkflowAction::class)->execute(
            $this->agentflowJson(),
            $this->team->id,
            $this->user->id,
        );

        $workflow = $result['workflow'];
        $this->assertSame('Imported Flowise flow', $workflow->name);
        $this->assertSame(WorkflowStatus::Draft, $workflow->status);
        $this->assertSame($this->team->id, $workflow->team_id);

        $types = $workflow->nodes()->orderBy('order')->get()->map(fn ($n) => $n->getAttribute('type')->value)->all();
        $this->assertSame(['start', 'llm', 'template_transform', 'end'], $types);
        $this->assertSame(3, $workflow->edges()->count());

        $llm = $workflow->nodes()->where('type', 'llm')->first();
        $this->assertNotNull($llm);
        $this->assertSame('anthropic/claude-haiku-4-5', $llm->getAttribute('config')['model']);

        $this->assertNotEmpty($result['conversion_warnings']);
    }

    public function test_name_override_is_used_for_flowise_import(): void
    {
        $result = app(ImportWorkflowAction::class)->execute(
            $this->agentflowJson(),
            $this->team->id,
            $this->user->id,
            'Support bot',
        );

        $this->assertSame('Support bot', $result['workflow']->name);
    }

    public function test_workflows_docs_page_has_flowise_migration_section(): void
    {
        $this->get(route('docs.show', 'workflows'))
            ->assertOk()
            ->assertSee('Migrating from Flowise');
    }

    public function test_native_import_returns_empty_conversion_warnings(): void
    {
        $result = app(ImportWorkflowAction::class)->execute([
            'version' => '1.0',
            'workflow' => ['name' => 'Native'],
            'nodes' => [
                ['id' => 's', 'type' => 'start', 'label' => 'Start'],
                ['id' => 'e', 'type' => 'end', 'label' => 'End'],
            ],
            'edges' => [['source_node_id' => 's', 'target_node_id' => 'e']],
        ], $this->team->id, $this->user->id);

        $this->assertSame('Native', $result['workflow']->name);
        $this->assertSame([], $result['conversion_warnings']);
    }
}
