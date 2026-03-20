<?php

namespace Tests\Feature\Domain\Tool;

use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Models\ToolEmbedding;
use App\Domain\Tool\Services\SemanticToolSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SemanticToolSelectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_tool_names_returns_empty_on_non_pgsql(): void
    {
        // SQLite test DB — should gracefully return empty collection
        $selector = new SemanticToolSelector;

        $result = $selector->searchToolNames(
            query: 'search for files',
            teamId: 'team-1',
            toolIds: ['tool-1'],
        );

        $this->assertTrue($result->isEmpty());
    }

    public function test_embed_tool_definitions_returns_zero_on_non_pgsql(): void
    {
        $selector = new SemanticToolSelector;

        $count = $selector->embedToolDefinitions('tool-1', 'team-1', [
            ['name' => 'search_files', 'description' => 'Search for files by pattern'],
        ]);

        $this->assertSame(0, $count);
    }

    public function test_remove_tool_embeddings_deletes_records(): void
    {
        $tool = Tool::factory()->create();

        $embedding = ToolEmbedding::withoutGlobalScopes()->create([
            'team_id' => $tool->team_id,
            'tool_id' => $tool->id,
            'prism_tool_name' => 'test_tool',
            'text_content' => 'test content',
        ]);

        $selector = new SemanticToolSelector;
        $deleted = $selector->removeToolEmbeddings($tool->id);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('tool_embeddings', ['id' => $embedding->id]);
    }

    public function test_threshold_reads_from_config(): void
    {
        config(['tools.semantic_filter_threshold' => 20]);

        $this->assertSame(20, SemanticToolSelector::threshold());
    }

    public function test_tool_embedding_model_belongs_to_tool(): void
    {
        $tool = Tool::factory()->create();

        $embedding = ToolEmbedding::withoutGlobalScopes()->create([
            'team_id' => $tool->team_id,
            'tool_id' => $tool->id,
            'prism_tool_name' => 'test_tool',
            'text_content' => 'test',
        ]);

        $this->assertTrue($embedding->tool->is($tool));
    }
}
