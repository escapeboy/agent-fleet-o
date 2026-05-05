<?php

namespace App\Domain\Chatbot\Jobs;

use App\Domain\Chatbot\Enums\KnowledgeSourceStatus;
use App\Domain\Chatbot\Enums\KnowledgeSourceType;
use App\Domain\Chatbot\Models\ChatbotKbChunk;
use App\Domain\Chatbot\Models\ChatbotKnowledgeSource;
use App\Domain\Integration\Services\WebclawResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Prism;

class IndexKnowledgeSourceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(private readonly string $sourceId) {}

    public function handle(): void
    {
        $source = ChatbotKnowledgeSource::find($this->sourceId);

        if (! $source || $source->trashed()) {
            return;
        }

        $source->update(['status' => KnowledgeSourceStatus::Indexing]);

        try {
            $chunks = match ($source->type) {
                KnowledgeSourceType::Document => $this->indexDocument($source),
                KnowledgeSourceType::Url => $this->indexUrl($source->source_url, $source->team_id),
                KnowledgeSourceType::Sitemap => $this->indexSitemap($source->source_url, $source->team_id),
                KnowledgeSourceType::Website => $this->indexWebsite($source->source_url, $source->source_data['max_pages'] ?? 30, $source->team_id),
            };

            // Delete existing chunks for this source (re-indexing case)
            ChatbotKbChunk::where('source_id', $source->id)->delete();

            // Embed and upsert chunks in batches of 20
            $totalChunks = 0;
            foreach (array_chunk($chunks, 20) as $batch) {
                $this->embedAndStore($source, $batch, $totalChunks);
                $totalChunks += count($batch);
            }

            $source->update([
                'status' => KnowledgeSourceStatus::Ready,
                'chunk_count' => $totalChunks,
                'indexed_at' => now(),
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('IndexKnowledgeSourceJob failed', [
                'source_id' => $source->id,
                'error' => $e->getMessage(),
            ]);
            $source->update([
                'status' => KnowledgeSourceStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    private function indexDocument(ChatbotKnowledgeSource $source): array
    {
        $data = $source->source_data ?? [];
        $path = $data['path'] ?? null;

        if (! $path || ! Storage::exists($path)) {
            throw new \RuntimeException("Document file not found: {$path}");
        }

        $content = Storage::get($path);

        return $this->chunkText($content);
    }

    private function indexUrl(string $url, ?string $teamId = null): array
    {
        $cfg = WebclawResolver::forTeam($teamId);

        try {
            $scrapeResponse = $cfg['http']->post($cfg['url'].'/v1/scrape', [
                'url' => $url,
                'format' => 'markdown',
            ]);

            if ($scrapeResponse->successful() && $scrapeResponse->json('content')) {
                return $this->chunkText($scrapeResponse->json('content'), ['url' => $url]);
            }
        } catch (\Throwable) {
            // Fall through to basic scrape
        }

        $response = Http::timeout(30)->get($url);
        $html = $response->body();
        $text = strip_tags($html);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $this->chunkText($text, ['url' => $url]);
    }

    private function indexWebsite(string $url, int $maxPages, ?string $teamId = null): array
    {
        $cfg = WebclawResolver::forTeam($teamId);

        try {
            $response = $cfg['http']->timeout(120)->post($cfg['url'].'/v1/crawl', [
                'url' => $url,
                'max_pages' => $maxPages,
                'format' => 'markdown',
            ]);

            if ($response->successful()) {
                $allChunks = [];
                foreach ($response->json('pages', []) as $page) {
                    $chunks = $this->chunkText($page['content'], [
                        'url' => $page['url'],
                        'title' => $page['metadata']['title'] ?? '',
                    ]);
                    $allChunks = array_merge($allChunks, $chunks);
                }

                return $allChunks;
            }
        } catch (\Throwable) {
            // Fall through to sitemap fallback
        }

        return $this->indexSitemap($url.'/sitemap.xml', $teamId);
    }

    private function indexSitemap(string $sitemapUrl, ?string $teamId = null): array
    {
        $response = Http::timeout(30)->get($sitemapUrl);
        $xml = simplexml_load_string($response->body());

        $allChunks = [];
        $urls = [];

        // Support sitemap index files
        if (isset($xml->sitemap)) {
            foreach ($xml->sitemap as $sitemapEntry) {
                $nestedResponse = Http::timeout(30)->get((string) $sitemapEntry->loc);
                $nestedXml = simplexml_load_string($nestedResponse->body());
                if ($nestedXml && isset($nestedXml->url)) {
                    foreach ($nestedXml->url as $urlEntry) {
                        $urls[] = (string) $urlEntry->loc;
                    }
                }
            }
        } elseif (isset($xml->url)) {
            foreach ($xml->url as $urlEntry) {
                $urls[] = (string) $urlEntry->loc;
            }
        }

        foreach (array_slice($urls, 0, 50) as $url) { // cap at 50 URLs per sitemap
            try {
                $chunks = $this->indexUrl($url, $teamId);
                $allChunks = array_merge($allChunks, $chunks);
            } catch (\Throwable) {
                // Skip individual URL failures
            }
        }

        return $allChunks;
    }

    /**
     * Split text into ~300-token chunks with 20% overlap.
     */
    private function chunkText(string $text, array $metadata = []): array
    {
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $chunkSize = 300;
        $overlap = 60; // ~20%
        $chunks = [];
        $i = 0;

        while ($i < count($words)) {
            $slice = array_slice($words, $i, $chunkSize);
            $chunks[] = [
                'content' => implode(' ', $slice),
                'metadata' => array_merge($metadata, ['word_offset' => $i]),
            ];
            $i += ($chunkSize - $overlap);
        }

        return $chunks;
    }

    private function embedAndStore(ChatbotKnowledgeSource $source, array $chunks, int $startIndex): void
    {
        foreach ($chunks as $idx => $chunk) {
            $text = $chunk['content'];

            $response = Prism::embeddings()
                ->using(config('memory.embedding_provider', 'openai'), config('memory.embedding_model', 'text-embedding-3-small'))
                ->fromInput($text)
                ->asEmbeddings();

            $vector = $response->embeddings[0]->embedding;
            $embeddingStr = '['.implode(',', $vector).']';

            DB::table('chatbot_kb_chunks')->insert([
                'id' => Str::orderedUuid(),
                'source_id' => $source->id,
                'chatbot_id' => $source->chatbot_id,
                'team_id' => $source->team_id,
                'content' => $text,
                'embedding' => DB::raw("'{$embeddingStr}'::vector"),
                'chunk_index' => $startIndex + $idx,
                'access_level' => $source->access_level ?? 'public',
                'metadata' => json_encode($chunk['metadata'] ?? []),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
