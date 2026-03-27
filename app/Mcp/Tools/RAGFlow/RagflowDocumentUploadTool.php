<?php

namespace App\Mcp\Tools\RAGFlow;

use App\Domain\Knowledge\Actions\IngestToRagflowAction;
use App\Domain\Knowledge\Models\KnowledgeBase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class RagflowDocumentUploadTool extends Tool
{
    protected string $name = 'ragflow_document_upload';

    protected string $description = 'Upload a text document to a RAGFlow-enabled knowledge base. The document will be processed by DeepDoc (OCR, layout analysis, table extraction) and indexed for hybrid retrieval.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'knowledge_base_id' => $schema->string()
                ->description('UUID of the RAGFlow-enabled knowledge base')
                ->required(),
            'content' => $schema->string()
                ->description('Document text content to upload')
                ->required(),
            'source_name' => $schema->string()
                ->description('Display name / filename for this document (e.g. report.pdf, Q4_summary.txt)')
                ->default('manual'),
            'mime_type' => $schema->string()
                ->description('MIME type hint for RAGFlow (text/plain, application/pdf, text/markdown, etc.)')
                ->default('text/plain'),
        ];
    }

    public function handle(Request $request, IngestToRagflowAction $action): Response
    {
        $request->validate([
            'knowledge_base_id' => 'required|string',
            'content' => 'required|string|min:1',
        ]);

        $teamId = app('mcp.team_id');
        $kb = KnowledgeBase::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $request->get('knowledge_base_id'))
            ->where('ragflow_enabled', true)
            ->firstOrFail();

        $action->execute(
            knowledgeBase: $kb,
            content: $request->get('content'),
            sourceName: $request->get('source_name', 'manual'),
            mimeType: $request->get('mime_type', 'text/plain'),
        );

        return Response::text(json_encode([
            'knowledge_base_id' => $kb->id,
            'message' => 'Document uploaded and queued for DeepDoc parsing. Check status with ragflow_document_parse.',
        ]));
    }
}
