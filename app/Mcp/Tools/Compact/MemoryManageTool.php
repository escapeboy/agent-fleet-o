<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Memory\MemoryAddTool;
use App\Mcp\Tools\Memory\MemoryDeleteTool;
use App\Mcp\Tools\Memory\MemoryListRecentTool;
use App\Mcp\Tools\Memory\MemorySearchTool;
use App\Mcp\Tools\Memory\MemoryStatsTool;
use App\Mcp\Tools\Memory\MemoryUploadKnowledgeTool;
use App\Mcp\Tools\Memory\SupabaseProvisionMemoryTool;

class MemoryManageTool extends CompactTool
{
    protected string $name = 'memory_manage';

    protected string $description = 'Manage team memory (semantic store). Actions: search (query, limit), list_recent (limit), stats, add (content, metadata), delete (memory_id), upload_knowledge (file content/url), supabase_provision (provision Supabase vector store).';

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
