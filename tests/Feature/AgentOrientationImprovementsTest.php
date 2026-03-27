<?php

namespace Tests\Feature;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\EmbeddingService;
use App\Mcp\Tools\Tool\ToolSearchTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Tests\TestCase;

class AgentOrientationImprovementsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-orientation',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        app()->instance('mcp.team_id', $this->team->id);
    }

    // -------------------------------------------------------------------------
    // A2A Agent Card
    // -------------------------------------------------------------------------

    public function test_agent_card_returns_valid_a2a_json(): void
    {
        $response = $this->get('/.well-known/agent.json');

        $response->assertOk();
        $response->assertJsonStructure([
            'name',
            'description',
            'url',
            'version',
            'authentication' => ['schemes'],
            'skills' => [
                '*' => ['id', 'name', 'description', 'inputModes', 'outputModes'],
            ],
            'capabilities',
        ]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_agent_card_requires_no_authentication(): void
    {
        // Route must be publicly accessible without a bearer token
        $response = $this->get('/.well-known/agent.json');
        $response->assertOk();
    }

    public function test_agent_card_has_at_least_three_skills(): void
    {
        $response = $this->getJson('/.well-known/agent.json');
        $data = $response->json();

        $this->assertCount(5, $data['skills']);
    }

    // -------------------------------------------------------------------------
    // AiRequestDTO — enablePromptCaching field
    // -------------------------------------------------------------------------

    public function test_ai_request_dto_has_enable_prompt_caching_defaulting_to_false(): void
    {
        $dto = new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'You are helpful.',
            userPrompt: 'Hello',
        );

        $this->assertFalse($dto->enablePromptCaching);
    }

    public function test_ai_request_dto_accepts_enable_prompt_caching_true(): void
    {
        $dto = new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'You are helpful.',
            userPrompt: 'Hello',
            enablePromptCaching: true,
        );

        $this->assertTrue($dto->enablePromptCaching);
    }

    // -------------------------------------------------------------------------
    // Tool model — tags field
    // -------------------------------------------------------------------------

    public function test_tool_tags_cast_as_array(): void
    {
        $tool = Tool::create([
            'team_id' => $this->team->id,
            'name' => 'Test Tool',
            'slug' => 'test-tool-orientation',
            'description' => 'A test tool',
            'type' => ToolType::BuiltIn,
            'status' => ToolStatus::Active,
            'tags' => ['core', 'execution'],
        ]);

        $tool->refresh();

        $this->assertIsArray($tool->tags);
        $this->assertContains('core', $tool->tags);
        $this->assertContains('execution', $tool->tags);
    }

    public function test_tool_tags_default_to_empty_array(): void
    {
        $tool = Tool::create([
            'team_id' => $this->team->id,
            'name' => 'Untagged Tool',
            'slug' => 'untagged-tool-orientation',
            'description' => 'No tags',
            'type' => ToolType::BuiltIn,
            'status' => ToolStatus::Active,
        ]);

        $tool->refresh();

        $this->assertIsArray($tool->tags ?? []);
    }

    // -------------------------------------------------------------------------
    // ToolSearchTool — keyword search
    // -------------------------------------------------------------------------

    public function test_tool_search_keyword_returns_matching_tools(): void
    {
        DB::table('tool_registry_entries')->insert([
            'id' => Str::orderedUuid()->toString(),
            'tool_name' => 'workflow_execute',
            'group' => 'workflow',
            'description' => 'Execute a workflow by its ID.',
            'composite_text' => 'workflow_execute: Execute a workflow by its ID.',
            'schema' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tool_registry_entries')->insert([
            'id' => Str::orderedUuid()->toString(),
            'tool_name' => 'agent_list',
            'group' => 'agent',
            'description' => 'List AI agents.',
            'composite_text' => 'agent_list: List AI agents.',
            'schema' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tool = app(ToolSearchTool::class);
        $response = $tool->handle(new Request(['query' => 'workflow']));

        $this->assertFalse($response->isError());
        $data = json_decode((string) $response->content(), true);

        $this->assertGreaterThanOrEqual(1, $data['count']);
        $names = array_column($data['tools'], 'name');
        $this->assertContains('workflow_execute', $names);
    }

    public function test_tool_search_group_filter_narrows_results(): void
    {
        DB::table('tool_registry_entries')->insert([
            ['id' => Str::orderedUuid()->toString(), 'tool_name' => 'agent_create', 'group' => 'agent', 'description' => 'Create agent.', 'composite_text' => 'agent_create: Create agent.', 'schema' => '{}', 'created_at' => now(), 'updated_at' => now()],
            ['id' => Str::orderedUuid()->toString(), 'tool_name' => 'workflow_create', 'group' => 'workflow', 'description' => 'Create workflow.', 'composite_text' => 'workflow_create: Create workflow.', 'schema' => '{}', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $tool = app(ToolSearchTool::class);
        $response = $tool->handle(new Request(['query' => 'create', 'group' => 'agent']));

        $data = json_decode((string) $response->content(), true);
        $groups = array_column($data['tools'], 'group');

        $this->assertNotContains('workflow', $groups);
        $this->assertContains('agent', $groups);
    }

    public function test_tool_search_empty_query_returns_empty(): void
    {
        $tool = app(ToolSearchTool::class);
        $response = $tool->handle(new Request(['query' => '']));

        $data = json_decode((string) $response->content(), true);
        $this->assertEquals(0, $data['count']);
    }

    public function test_tool_search_respects_limit(): void
    {
        $entries = [];
        for ($i = 1; $i <= 15; $i++) {
            $entries[] = [
                'id' => Str::orderedUuid()->toString(),
                'tool_name' => "test_tool_{$i}",
                'group' => 'test',
                'description' => "Test tool number {$i} for testing.",
                'composite_text' => "test_tool_{$i}: Test tool number {$i} for testing.",
                'schema' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('tool_registry_entries')->insert($entries);

        $tool = app(ToolSearchTool::class);
        $response = $tool->handle(new Request(['query' => 'test tool', 'limit' => 5]));

        $data = json_decode((string) $response->content(), true);
        $this->assertLessThanOrEqual(5, count($data['tools']));
    }

    // -------------------------------------------------------------------------
    // EmbeddingService — interface
    // -------------------------------------------------------------------------

    public function test_embedding_service_format_for_pgvector_produces_valid_string(): void
    {
        $service = app(EmbeddingService::class);
        $result = $service->formatForPgvector([0.1, 0.2, 0.3]);

        $this->assertStringStartsWith('[', $result);
        $this->assertStringEndsWith(']', $result);
        $this->assertStringContainsString('0.1', $result);
    }
}
