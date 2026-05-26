<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Mcp\Servers\AgentFleetServer;
use App\Mcp\Tools\Skill\SkillExportAgentSkillsTool;
use App\Mcp\Tools\Skill\SkillImportAgentSkillsTool;
use App\Mcp\Tools\Skill\SkillImportGitHubTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use ReflectionClass;
use Tests\TestCase;

class SkillAgentSkillsToolsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Tools Team',
            'slug' => 'tools-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($user);
        app()->instance('mcp.team_id', $this->team->id);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    public function test_export_tool_returns_skill_md(): void
    {
        $skill = Skill::factory()->for($this->team)->create([
            'name' => 'Exportable',
            'slug' => 'exportable',
            'system_prompt' => 'Do the thing.',
        ]);

        $response = (new SkillExportAgentSkillsTool)->handle(new Request(['skill_id' => $skill->id]));

        $this->assertStringStartsWith('---', (string) $response->content());
        $this->assertStringContainsString('name: exportable', (string) $response->content());
    }

    public function test_export_tool_unknown_skill_returns_error(): void
    {
        $response = (new SkillExportAgentSkillsTool)->handle(new Request(['skill_id' => 'does-not-exist']));

        $this->assertArrayHasKey('error', $this->decode($response));
    }

    public function test_import_tool_creates_skill(): void
    {
        $md = "---\nname: imported-skill\ndescription: Imported via MCP.\n---\n\n# Body\n\nInstructions.";

        $response = (new SkillImportAgentSkillsTool)->handle(new Request(['skill_md' => $md]));
        $data = $this->decode($response);

        $this->assertTrue($data['success']);
        $skill = Skill::withoutGlobalScopes()->where('team_id', $this->team->id)->find($data['id']);
        $this->assertNotNull($skill);
        $this->assertSame(SkillType::Llm, $skill->type);
    }

    public function test_import_tool_invalid_document_returns_error(): void
    {
        $response = (new SkillImportAgentSkillsTool)->handle(new Request(['skill_md' => 'no frontmatter at all']));

        $this->assertArrayHasKey('error', $this->decode($response));
    }

    public function test_import_tool_reports_missing_skillkit_sections(): void
    {
        $md = "---\nname: sectionless\ndescription: No sections.\n---\n\n# Body\n\nNo recommended sections.";

        $response = (new SkillImportAgentSkillsTool)->handle(new Request(['skill_md' => $md]));
        $data = $this->decode($response);

        $this->assertContains('When to Use', $data['section_warnings']);
        $this->assertContains('Boundaries', $data['section_warnings']);
    }

    public function test_github_import_tool_creates_skills(): void
    {
        Http::fake([
            'api.github.com/repos/octo/repo/contents/SKILL.md*' => Http::response([
                'type' => 'file',
                'name' => 'SKILL.md',
                'path' => 'SKILL.md',
                'encoding' => 'base64',
                'content' => base64_encode("---\nname: from-github\ndescription: Pulled from GitHub.\n---\n\n## When to Use\n\nx\n\n## Boundaries\n\ny"),
            ]),
        ]);

        $response = (new SkillImportGitHubTool)->handle(new Request(['source' => 'octo/repo/SKILL.md']));
        $data = $this->decode($response);

        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['imported']);
        $this->assertSame('from-github', $data['imported'][0]['name']);
        $skill = Skill::withoutGlobalScopes()->where('team_id', $this->team->id)->find($data['imported'][0]['id']);
        $this->assertNotNull($skill);
    }

    public function test_github_import_tool_invalid_source_returns_error(): void
    {
        $response = (new SkillImportGitHubTool)->handle(new Request(['source' => 'no-repo']));

        $this->assertArrayHasKey('error', $this->decode($response));
    }

    public function test_tools_are_registered_in_server(): void
    {
        $ref = new ReflectionClass(AgentFleetServer::class);
        $prop = $ref->getProperty('tools');
        $prop->setAccessible(true);
        $tools = $prop->getValue($ref->newInstanceWithoutConstructor());

        $this->assertContains(SkillExportAgentSkillsTool::class, $tools);
        $this->assertContains(SkillImportAgentSkillsTool::class, $tools);
        $this->assertContains(SkillImportGitHubTool::class, $tools);
    }
}
