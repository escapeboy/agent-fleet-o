<?php

namespace App\Domain\Chatbot\Actions;

use App\Domain\Chatbot\Enums\KnowledgeSourceStatus;
use App\Domain\Chatbot\Enums\KnowledgeSourceType;
use App\Domain\Chatbot\Jobs\IndexKnowledgeSourceJob;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotKnowledgeSource;

class CreateKnowledgeSourceAction
{
    /**
     * @param  array{name: string, type: string, source_url?: string, source_data?: array}  $data
     */
    public function execute(Chatbot $chatbot, array $data): ChatbotKnowledgeSource
    {
        $source = ChatbotKnowledgeSource::create([
            'chatbot_id' => $chatbot->id,
            'team_id' => $chatbot->team_id,
            'type' => $data['type'],
            'name' => $data['name'],
            'source_url' => $data['source_url'] ?? null,
            'source_data' => $data['source_data'] ?? null,
            'status' => KnowledgeSourceStatus::Pending,
        ]);

        IndexKnowledgeSourceJob::dispatch($source->id)->onQueue('ai-calls');

        return $source;
    }
}
