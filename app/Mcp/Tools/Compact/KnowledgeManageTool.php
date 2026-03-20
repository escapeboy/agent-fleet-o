<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Knowledge\KnowledgeBaseCreateTool;
use App\Mcp\Tools\Knowledge\KnowledgeBaseDeleteTool;
use App\Mcp\Tools\Knowledge\KnowledgeBaseIngestTool;
use App\Mcp\Tools\Knowledge\KnowledgeBaseListTool;
use App\Mcp\Tools\Knowledge\KnowledgeBaseSearchTool;

class KnowledgeManageTool extends CompactTool
{
    protected string $name = 'knowledge_manage';

    protected string $description = 'Manage knowledge bases. Actions: list, create (name, description), ingest (knowledge_base_id, content/url), search (knowledge_base_id, query), delete (knowledge_base_id).';

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
