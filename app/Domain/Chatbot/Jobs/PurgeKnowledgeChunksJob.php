<?php

namespace App\Domain\Chatbot\Jobs;

use App\Domain\Chatbot\Models\ChatbotKbChunk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PurgeKnowledgeChunksJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $sourceId) {}

    public function handle(): void
    {
        ChatbotKbChunk::where('source_id', $this->sourceId)->delete();
    }
}
