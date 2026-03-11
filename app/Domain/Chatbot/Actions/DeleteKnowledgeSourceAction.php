<?php

namespace App\Domain\Chatbot\Actions;

use App\Domain\Chatbot\Jobs\PurgeKnowledgeChunksJob;
use App\Domain\Chatbot\Models\ChatbotKnowledgeSource;

class DeleteKnowledgeSourceAction
{
    public function execute(ChatbotKnowledgeSource $source): void
    {
        $sourceId = $source->id;
        $source->delete(); // soft-delete
        PurgeKnowledgeChunksJob::dispatch($sourceId)->onQueue('default');
    }
}
