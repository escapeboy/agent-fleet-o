<?php

namespace App\Domain\Chatbot\Actions;

use App\Domain\Chatbot\Enums\KnowledgeSourceStatus;
use App\Domain\Chatbot\Enums\KnowledgeSourceType;
use App\Domain\Chatbot\Jobs\IndexGitRepositoryJob;
use App\Domain\Chatbot\Jobs\IndexKnowledgeSourceJob;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotKnowledgeSource;

class CreateKnowledgeSourceAction
{
    /**
     * @param  array{name: string, type: string, source_url?: string, source_data?: array, access_level?: string}  $data
     */
    public function execute(Chatbot $chatbot, array $data): ChatbotKnowledgeSource
    {
        $type = KnowledgeSourceType::from($data['type']);

        $source = ChatbotKnowledgeSource::create([
            'chatbot_id' => $chatbot->id,
            'team_id' => $chatbot->team_id,
            'type' => $type,
            'name' => $data['name'],
            'access_level' => $data['access_level'] ?? 'public',
            'source_url' => $data['source_url'] ?? null,
            'source_data' => $data['source_data'] ?? null,
            'status' => KnowledgeSourceStatus::Pending,
        ]);

        if ($type === KnowledgeSourceType::GitRepository) {
            IndexGitRepositoryJob::dispatch($source->id)->onQueue('ai-calls');
        } else {
            IndexKnowledgeSourceJob::dispatch($source->id)->onQueue('ai-calls');
        }

        return $source;
    }
}
