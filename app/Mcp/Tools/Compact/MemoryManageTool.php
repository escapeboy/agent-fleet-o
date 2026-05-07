<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Memory\MemoryAddTool;
use App\Mcp\Tools\Memory\MemoryDeleteTool;
use App\Mcp\Tools\Memory\MemoryListRecentTool;
use App\Mcp\Tools\Memory\MemorySearchTool;
use App\Mcp\Tools\Memory\MemoryStatsTool;
use App\Mcp\Tools\Memory\MemoryUploadKnowledgeTool;
use App\Mcp\Tools\Memory\SupabaseProvisionMemoryTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class MemoryManageTool extends CompactTool
{
    protected string $name = 'memory_manage';

    protected string $description = <<<'TXT'
Team-scoped semantic memory — short snippets agents store and retrieve across conversations (preferences, constraints, prior decisions). Backed by pgvector with HNSW indexing. Distinct from `knowledge_manage`: memory is unstructured short notes; knowledge bases are document corpora.

Actions:
- search (read) — query, optional limit (default 20). Semantic similarity ranking.
- list_recent (read) — optional limit (default 50). Reverse chronological.
- stats (read) — total entries, embedding dim, last write.
- add (write — costs embedding credits) — content, optional metadata (object).
- delete (DESTRUCTIVE) — memory_id. Hard delete.
- upload_knowledge (write — costs embedding credits) — one of: file content, url. Bulk-ingests into memory.
- supabase_provision (write) — credentials. Switches the team to a Supabase-backed vector store.
TXT;

    protected function toolMap(): array
    {
        return [
            'search' => MemorySearchTool::class,
            'list_recent' => MemoryListRecentTool::class,
            'stats' => MemoryStatsTool::class,
            'add' => MemoryAddTool::class,
            'delete' => MemoryDeleteTool::class,
            'upload_knowledge' => MemoryUploadKnowledgeTool::class,
            'supabase_provision' => SupabaseProvisionMemoryTool::class,
        ];
    }
}
