<?php

namespace App\Mcp\Tools\Knowledge;

use App\Domain\Knowledge\Actions\IngestDocumentAction;
use App\Domain\Knowledge\Models\KnowledgeBase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class KnowledgeBaseIngestTool extends Tool
{
    protected string $name = 'knowledge_base_ingest';

    protected string $description = 'Ingest text content into a knowledge base. The content is chunked, embedded, and stored for semantic retrieval. Processing is async — check status with knowledge_base_list.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'knowledge_base_id' => $schema->string()
                ->description('UUID of the knowledge base')
                ->required(),
            'content' => $schema->string()
                ->description('Raw text content to ingest')
                ->required(),
            'source_name' => $schema->string()
                ->description('Display name for this source (e.g. filename, URL)')
                ->default('manual'),
            'source_type' => $schema->string()
                ->description('Source type: text, file, or url')
                ->default('text'),
            'reindex' => $schema->boolean()
                ->description('If true, delete existing chunks for this source before re-ingesting')
                ->default(false),
        ];
    }

    public function handle(Request $request, IngestDocumentAction $action): Response
    {
        $request->validate([
            'knowledge_base_id' => 'required|string',
            'content' => 'required|string|min:10',
        ]);

        $kb = KnowledgeBase::withoutGlobalScopes()->find($request->get('knowledge_base_id'));

        if (! $kb) {
            return Response::text(json_encode(['error' => 'Knowledge base not found.']));
        }

        $action->execute(
            knowledgeBase: $kb,
            content: $request->get('content'),
            sourceName: $request->get('source_name', 'manual'),
            sourceType: $request->get('source_type', 'text'),
            reindex: (bool) $request->get('reindex', false),
        );

        return Response::text(json_encode([
            'message' => 'Ingestion queued.',
            'knowledge_base_id' => $kb->id,
            'status' => 'ingesting',
        ]));
    }
}
