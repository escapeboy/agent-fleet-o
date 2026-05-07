<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Knowledge\KnowledgeBaseCreateTool;
use App\Mcp\Tools\Knowledge\KnowledgeBaseDeleteTool;
use App\Mcp\Tools\Knowledge\KnowledgeBaseIngestTool;
use App\Mcp\Tools\Knowledge\KnowledgeBaseListTool;
use App\Mcp\Tools\Knowledge\KnowledgeBaseSearchTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class KnowledgeManageTool extends CompactTool
{
    protected string $name = 'knowledge_manage';

    protected string $description = <<<'TXT'
Per-team knowledge bases — vector-indexed document collections agents can search at runtime. Ingestion runs an embedding job (consumes credits via the team's embedding provider). `search` is hybrid: cosine similarity over pgvector + keyword fallback.

Actions:
- list (read) — all knowledge bases for the team.
- create (write) — name, description.
- ingest (write — costs embedding credits) — knowledge_base_id; one of: content (raw text), url (fetched + extracted), file_id.
- search (read) — knowledge_base_id, query; optional limit (default 10), threshold.
- delete (DESTRUCTIVE) — knowledge_base_id. Drops all ingested chunks and embeddings.
TXT;

    protected function toolMap(): array
    {
        return [
            'list' => KnowledgeBaseListTool::class,
            'create' => KnowledgeBaseCreateTool::class,
            'ingest' => KnowledgeBaseIngestTool::class,
            'search' => KnowledgeBaseSearchTool::class,
            'delete' => KnowledgeBaseDeleteTool::class,
        ];
    }
}
