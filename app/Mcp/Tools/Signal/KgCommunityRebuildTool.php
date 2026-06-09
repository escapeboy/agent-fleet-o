<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\KnowledgeGraph\Actions\BuildKgCommunitiesAction;
use App\Domain\KnowledgeGraph\Models\KgCommunity;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class KgCommunityRebuildTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'kg_community_rebuild';

    // WARNING: synchronous + LLM-backed (one LLM call per community) + DELETES all
    // existing communities for the team before recreating. Slow and costly on large graphs.
    protected string $description = 'Rebuild this team\'s knowledge graph communities from scratch using the Louvain algorithm. WARNING: runs synchronously, DELETES all existing communities for the team, then makes one LLM call per detected community to generate a summary. This can be slow and costly on large graphs. Returns the resulting community count.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        app(BuildKgCommunitiesAction::class)->execute($teamId);

        $count = KgCommunity::query()
            ->where('team_id', $teamId)
            ->count();

        return Response::text(json_encode([
            'success' => true,
            'message' => 'Communities rebuilt.',
            'community_count' => $count,
        ]));
    }
}
