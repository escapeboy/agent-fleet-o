<?php

namespace Tests\Feature\Domain\Agent;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Agent\Services\SandboxedWorkspace;
use App\Domain\Agent\Services\WorkspaceContractWriter;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceContractTest extends TestCase
{
    use RefreshDatabase;

    private string $sandboxBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandboxBase = sys_get_temp_dir().'/fleetq-test-sandboxes-'.Str::uuid7();
        @mkdir($this->sandboxBase, 0o700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->sandboxBase)) {
            File::deleteDirectory($this->sandboxBase);
        }
        parent::tearDown();
    }

    public function test_prepare_writes_four_files_into_sandbox(): void
    {
        $execution = $this->makeExecution();
        $sandbox = new SandboxedWorkspace($execution->id, $execution->agent_id, $execution->team_id, $this->sandboxBase);

        $writer = app(WorkspaceContractWriter::class);
        $snapshot = $writer->prepare($execution, $sandbox);

        foreach (['AGENTS.md', 'feature-list.json', 'progress.md', 'init.sh'] as $file) {
            $this->assertFileExists($sandbox->resolve($file));
        }

        $this->assertStringContainsString('AGENTS.md', $snapshot->agentsMd);
        $this->assertStringContainsString('feature-list.json', $snapshot->agentsMd);

        $features = json_decode($snapshot->featureListJson, true);
        $this->assertIsArray($features);
        $this->assertSame($execution->id, $features['execution_id']);
        $this->assertNotEmpty($features['features']);
    }

    public function test_prepare_persists_snapshot_to_agent_execution(): void
    {
        $execution = $this->makeExecution();

        app(WorkspaceContractWriter::class)->prepare($execution);

        $reloaded = AgentExecution::find($execution->id);
        $this->assertIsArray($reloaded->workspace_contract);
        $this->assertArrayHasKey('agents_md', $reloaded->workspace_contract);
        $this->assertArrayHasKey('feature_list_json', $reloaded->workspace_contract);
    }

    public function test_restore_or_prepare_uses_persisted_snapshot_when_present(): void
    {
        $execution = $this->makeExecution();
        $execution->update(['workspace_contract' => [
            'agents_md' => '# pinned\n',
            'feature_list_json' => '{"pinned": true}',
            'progress_md' => "# pinned\n",
            'init_sh' => "#!/bin/sh\n",
        ]]);

        $snapshot = app(WorkspaceContractWriter::class)->restoreOrPrepare($execution);

        $this->assertSame('# pinned\n', $snapshot->agentsMd);
        $this->assertSame('{"pinned": true}', $snapshot->featureListJson);
    }

    public function test_append_progress_updates_persisted_snapshot(): void
    {
        $execution = $this->makeExecution();
        $writer = app(WorkspaceContractWriter::class);
        $writer->prepare($execution);

        $writer->appendProgress($execution, 'ran the tests, 12 passed');
        $writer->appendProgress($execution, 'committed and pushed');

        $reloaded = AgentExecution::find($execution->id);
        $progress = $reloaded->workspace_contract['progress_md'] ?? '';
        $this->assertStringContainsString('ran the tests, 12 passed', $progress);
        $this->assertStringContainsString('committed and pushed', $progress);
    }

    public function test_default_feature_uses_execution_input_as_done_criteria(): void
    {
        $execution = $this->makeExecution(['input' => ['goal' => 'make X work']]);

        $snapshot = app(WorkspaceContractWriter::class)->prepare($execution);
        $features = json_decode($snapshot->featureListJson, true);

        $this->assertCount(1, $features['features']);
        $this->assertSame('Primary execution goal', $features['features'][0]['title']);
        $this->assertSame(['goal' => 'make X work'], $features['features'][0]['done_criteria']);
    }

    private function makeExecution(array $overrides = []): AgentExecution
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create();

        $exec = new AgentExecution([
            'agent_id' => $agent->id,
            'team_id' => $team->id,
            'status' => 'running',
            'input' => $overrides['input'] ?? ['placeholder' => true],
        ] + $overrides);
        $exec->save();

        return $exec;
    }
}
