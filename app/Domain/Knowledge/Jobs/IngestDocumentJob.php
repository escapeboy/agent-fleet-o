<?php

namespace App\Domain\Knowledge\Jobs;

use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Knowledge\Services\PgVectorKnowledgeStore;
use App\Domain\Knowledge\Services\PrismEmbeddingsProvider;
use App\Domain\Shared\Models\TeamProviderCredential;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use NeuronAI\RAG\DataLoader\StringDataLoader;
use NeuronAI\RAG\Splitter\SentenceTextSplitter;

class IngestDocumentJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        private readonly string $knowledgeBaseId,
        private readonly string $content,
        private readonly string $sourceName = 'manual',
        private readonly string $sourceType = 'text',
        private readonly bool $reindex = false,
    ) {}

    public function handle(PrismEmbeddingsProvider $embedder): void
    {
        $kb = KnowledgeBase::find($this->knowledgeBaseId);
        if (! $kb) {
            return;
        }

        $this->resolveTeamApiKey($kb->team_id);

        $kb->markIngesting();

        try {
            $store = new PgVectorKnowledgeStore($this->knowledgeBaseId);

            // Remove existing chunks for this source if reindexing
            if ($this->reindex) {
                $store->deleteBy($this->sourceType, $this->sourceName);
            }

            // Chunk the content using Neuron's SentenceTextSplitter
            $loader = new StringDataLoader($this->content);
            $loader->withSplitter(new SentenceTextSplitter(maxWords: 200, overlapWords: 20));
            $documents = $loader->getDocuments();

            // Tag each document with source metadata
            foreach ($documents as $doc) {
                $doc->sourceName = $this->sourceName;
                $doc->sourceType = $this->sourceType;
            }

            // Embed and store in batches to avoid memory issues
            $batches = array_chunk($documents, 50);
            foreach ($batches as $batch) {
                $embedded = $embedder->embedDocuments($batch);
                foreach ($embedded as $doc) {
                    $store->addDocument($doc);
                }
            }

            $kb->markReady($kb->chunks()->count());

            Log::info('Knowledge base ingestion complete', [
                'knowledge_base_id' => $this->knowledgeBaseId,
                'source_name' => $this->sourceName,
                'chunks' => count($documents),
            ]);
        } catch (\Throwable $e) {
            $kb->markError();

            Log::error('Knowledge base ingestion failed', [
                'knowledge_base_id' => $this->knowledgeBaseId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Set the team's BYOK OpenAI key in config so Prism uses it for embeddings.
     */
    private function resolveTeamApiKey(string $teamId): void
    {
        $credential = TeamProviderCredential::where('team_id', $teamId)
            ->where('provider', 'openai')
            ->where('is_active', true)
            ->first();

        if ($credential && isset($credential->credentials['api_key'])) {
            config(['prism.providers.openai.api_key' => $credential->credentials['api_key']]);
        }
    }
}
