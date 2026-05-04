<?php

namespace Tests\Feature\Domain\Agent;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Services\WorkspaceContractWriter;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceContractBuildSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_snapshot_for_execution_returns_all_four_files(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create([
            'name' => 'CodingBot',
            'role' => 'Senior Developer',
            'goal' => 'Ship a clean, tested PR.',
        ]);

        $writer = app(WorkspaceContractWriter::class);
        $snapshot = $writer->buildSnapshotForExecution(
            agent: $agent,
            experimentId: null,
            project: null,
            input: ['task' => 'Fix the auth bug'],
        );

        $this->assertStringContainsString('CodingBot', $snapshot->agentsMd);
        $this->assertStringContainsString('Senior Developer', $snapshot->agentsMd);
        $this->assertStringContainsString('Ship a clean', $snapshot->agentsMd);

        $featureList = json_decode($snapshot->featureListJson, true);
        $this->assertSame($agent->id, $featureList['agent_id']);
        $this->assertCount(1, $featureList['features']);
        $this->assertSame('Primary execution goal', $featureList['features'][0]['title']);

        $this->assertStringContainsString('progress.md', $snapshot->progressMd);
        $this->assertStringContainsString('## Iteration log', $snapshot->progressMd);

        $this->assertStringContainsString('#!/usr/bin/env bash', $snapshot->initSh);
    }

    public function test_build_snapshot_includes_project_title_in_rules(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create();
        $project = Project::factory()->for($team)->create(['title' => 'Q3 Refactor']);

        $writer = app(WorkspaceContractWriter::class);
        $snapshot = $writer->buildSnapshotForExecution(
            agent: $agent,
            experimentId: null,
            project: $project,
            input: ['task' => 'Refactor module X'],
        );

        $this->assertStringContainsString("'Q3 Refactor'", $snapshot->agentsMd);
    }

    public function test_snapshot_serializes_to_array(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create();

        $snapshot = app(WorkspaceContractWriter::class)->buildSnapshotForExecution(
            agent: $agent,
            experimentId: null,
            project: null,
            input: [],
        );
        $arr = $snapshot->toArray();

        $this->assertArrayHasKey('agents_md', $arr);
        $this->assertArrayHasKey('feature_list_json', $arr);
        $this->assertArrayHasKey('progress_md', $arr);
        $this->assertArrayHasKey('init_sh', $arr);
    }
}
