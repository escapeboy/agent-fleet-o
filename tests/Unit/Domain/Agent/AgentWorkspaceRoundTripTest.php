<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\Actions\ExportAgentWorkspaceAction;
use App\Domain\Agent\Actions\ImportAgentWorkspaceAction;
use App\Domain\Agent\Enums\AgentReasoningStrategy;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Models\Skill;
use App\Domain\Tool\Models\Tool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class AgentWorkspaceRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(Team $team): Agent
    {
        $agent = Agent::factory()->create([
            'team_id' => $team->id,
            'role' => 'Researcher',
            'goal' => 'Find prior art',
            'backstory' => 'Seasoned analyst',
            'reasoning_strategy' => AgentReasoningStrategy::ReAct,
            'capabilities' => ['web_search', 'summarize'],
            'constraints' => ['Cite every source'],
            'output_schema' => ['type' => 'object', 'properties' => ['answer' => ['type' => 'string']]],
        ]);

        $tool = Tool::factory()->create(['team_id' => $team->id, 'name' => 'github-search']);
        $skill = Skill::factory()->create(['team_id' => $team->id, 'slug' => 'summarize-doc']);
        $agent->tools()->attach($tool->id, ['priority' => 5]);
        $agent->skills()->attach($skill->id, ['priority' => 3]);

        return $agent->fresh();
    }

    public function test_yaml_export_captures_full_definition_including_skills(): void
    {
        $team = Team::factory()->create();
        $agent = $this->makeAgent($team);

        $path = app(ExportAgentWorkspaceAction::class)->execute($agent, 'yaml', includeMemories: false);
        $dump = Yaml::parse(file_get_contents($path));

        $this->assertSame(['web_search', 'summarize'], $dump['identity']['capabilities']);
        $this->assertSame(['Cite every source'], $dump['identity']['constraints']);
        $this->assertSame('react', $dump['identity']['reasoning_strategy']);
        $this->assertSame('object', $dump['identity']['output_schema']['type']);
        $this->assertSame('summarize-doc', $dump['skills'][0]['slug']);
        $this->assertSame('github-search', $dump['tools'][0]['name']);
    }

    public function test_round_trips_definition_tools_and_skills_into_same_team(): void
    {
        $team = Team::factory()->create();
        $agent = $this->makeAgent($team);

        $path = app(ExportAgentWorkspaceAction::class)->execute($agent, 'yaml', includeMemories: false);
        $result = app(ImportAgentWorkspaceAction::class)->executeFromPath($path, $team->id, 'create');

        $new = Agent::findOrFail($result['agent_id']);

        $this->assertSame('Researcher', $new->role);
        $this->assertSame('Find prior art', $new->goal);
        $this->assertSame('Seasoned analyst', $new->backstory);
        $this->assertSame(['web_search', 'summarize'], $new->capabilities);
        $this->assertSame(['Cite every source'], $new->constraints);
        $this->assertSame(AgentReasoningStrategy::ReAct, $new->reasoning_strategy);
        $this->assertSame('object', $new->output_schema['type']);
        $this->assertSame(1, $result['tools_linked']);
        $this->assertSame(1, $result['skills_linked']);
        $this->assertTrue($new->skills()->where('slug', 'summarize-doc')->exists());
        $this->assertTrue($new->tools()->where('name', 'github-search')->exists());
    }

    public function test_missing_skill_in_target_team_is_skipped_not_fatal(): void
    {
        $source = Team::factory()->create();
        $agent = $this->makeAgent($source);
        $path = app(ExportAgentWorkspaceAction::class)->execute($agent, 'yaml', includeMemories: false);

        // Import into a different team that has neither the tool nor the skill.
        $target = Team::factory()->create();
        $result = app(ImportAgentWorkspaceAction::class)->executeFromPath($path, $target->id, 'create');

        $this->assertSame(0, $result['skills_linked']);
        $this->assertSame(0, $result['tools_linked']);
        // Agent itself still imported, with its definition intact.
        $new = Agent::findOrFail($result['agent_id']);
        $this->assertSame('Researcher', $new->role);
        $this->assertSame(AgentReasoningStrategy::ReAct, $new->reasoning_strategy);
    }
}
