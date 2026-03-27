<?php

namespace App\Infrastructure\RAGFlow;

use App\Infrastructure\RAGFlow\DTOs\RAGFlowChunk;
use App\Infrastructure\RAGFlow\Exceptions\RAGFlowException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the RAGFlow REST API.
 *
 * API docs: https://ragflow.io/docs/http_api_reference
 * Base URL:  {RAGFLOW_URL}/api/v1/
 * Auth:      Authorization: Bearer {api_key}
 */
class RAGFlowClient
{
    private const CIRCUIT_BREAKER_KEY = 'ragflow.circuit_open';

    private const CIRCUIT_FAILURE_KEY = 'ragflow.failure_count';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout = 30,
        private readonly int $circuitBreakerTtl = 60,
        private readonly int $circuitBreakerThreshold = 5,
    ) {}

    // -------------------------------------------------------------------------
    // Dataset management
    // -------------------------------------------------------------------------

    /**
     * Create a new knowledge base dataset on RAGFlow.
     */
    public function createDataset(
        string $name,
        string $chunkMethod = 'general',
        string $embeddingModel = 'BAAI/bge-small-en-v1.5',
    ): array {
        return $this->post('/datasets', [
            'name' => $name,
            'chunk_method' => $chunkMethod,
            'embedding_model' => $embeddingModel,
        ]);
    }

    /**
     * Delete a dataset and all its documents.
     */
    public function deleteDataset(string $datasetId): void
    {
        $this->delete("/datasets/{$datasetId}");
    }

    /**
     * Get dataset metadata and status.
     */
    public function getDataset(string $datasetId): array
    {
        return $this->get("/datasets/{$datasetId}");
    }

    // -------------------------------------------------------------------------
    // Document lifecycle
    // -------------------------------------------------------------------------

    /**
     * Upload raw text content as a document.
     */
    public function uploadDocument(
        string $datasetId,
        string $filename,
        string $content,
        string $mimeType = 'text/plain',
    ): array {
        $response = Http::timeout($this->timeout)
            ->withToken($this->apiKey)
            ->attach('file', $content, $filename, ['Content-Type' => $mimeType])
            ->post($this->url("/datasets/{$datasetId}/documents"));

        return $this->parseResponse($response, "uploadDocument[{$datasetId}]");
    }

    /**
     * Upload a file from disk.
     *
     * @throws \InvalidArgumentException if the path is outside application storage
     */
    public function uploadDocumentFile(string $datasetId, string $filePath): array
    {
        $realPath = realpath($filePath);
        $storagePath = realpath(storage_path());

        if ($realPath === false || $storagePath === false || ! str_starts_with($realPath, $storagePath.DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('File path must be within the application storage directory.');
        }

        $response = Http::timeout($this->timeout)
            ->withToken($this->apiKey)
            ->attach('file', fopen($realPath, 'r'), basename($realPath))
            ->post($this->url("/datasets/{$datasetId}/documents"));

        return $this->parseResponse($response, "uploadDocumentFile[{$datasetId}]");
    }

    /**
     * Trigger async document parsing (DeepDoc pipeline).
     */
    public function parseDocuments(string $datasetId, array $documentIds): void
    {
        $this->post("/datasets/{$datasetId}/chunks", [
            'document_ids' => $documentIds,
        ]);
    }

    /**
     * Get the parse status of a document.
     *
     * @return array{status: string, chunk_count: int, token_count: int}
     */
    public function getDocumentStatus(string $datasetId, string $documentId): array
    {
        $data = $this->get("/datasets/{$datasetId}/documents");
        $docs = $data['docs'] ?? $data['data'] ?? [];

        foreach ($docs as $doc) {
            if (($doc['id'] ?? '') === $documentId) {
                return $doc;
            }
        }

        return ['status' => 'unknown', 'chunk_count' => 0, 'token_count' => 0];
    }

    /**
     * List all documents in a dataset.
     */
    public function listDocuments(string $datasetId, int $page = 1, int $pageSize = 50): array
    {
        return $this->get("/datasets/{$datasetId}/documents", [
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    // -------------------------------------------------------------------------
    // Retrieval
    // -------------------------------------------------------------------------

    /**
     * Retrieve relevant chunks for a query.
     *
     * @param  array{top_k?: int, similarity_threshold?: float, vector_similarity_weight?: float, use_kg?: bool, rerank_id?: string}  $options
     * @return RAGFlowChunk[]
     */
    public function retrieve(string $datasetId, string $query, array $options = []): array
    {
        if ($this->isCircuitOpen()) {
            throw new RAGFlowException('RAGFlow circuit breaker is open', 503);
        }

        try {
            $payload = array_merge([
                'question' => $query,
                'dataset_ids' => [$datasetId],
                'similarity_threshold' => config('ragflow.similarity_threshold', 0.2),
                'vector_similarity_weight' => config('ragflow.vector_weight', 0.3),
                'top_k' => config('ragflow.retrieval_top_k', 8),
                'use_kg' => false,
            ], $options);

            $data = $this->post('/retrieval', $payload);
            $this->resetCircuitFailures();

            $chunks = $data['chunks'] ?? $data['data']['chunks'] ?? [];

            return array_map(
                static fn (array $chunk) => RAGFlowChunk::fromArray($chunk),
                $chunks,
            );
        } catch (RAGFlowException $e) {
            $this->recordCircuitFailure();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Knowledge graph (GraphRAG)
    // -------------------------------------------------------------------------

    /**
     * Trigger asynchronous GraphRAG build.
     */
    public function buildKnowledgeGraph(string $datasetId, string $strategy = 'general'): void
    {
        $this->post("/datasets/{$datasetId}/run_graphrag", [
            'strategy' => $strategy,
        ]);
    }

    /**
     * Get knowledge graph build status.
     */
    public function getKnowledgeGraphStatus(string $datasetId): array
    {
        return $this->get("/datasets/{$datasetId}/trace_graphrag");
    }

    // -------------------------------------------------------------------------
    // RAPTOR
    // -------------------------------------------------------------------------

    /**
     * Trigger asynchronous RAPTOR hierarchical summarization build.
     */
    public function buildRaptor(string $datasetId): void
    {
        $this->post("/datasets/{$datasetId}/run_raptor");
    }

    /**
     * Get RAPTOR build status.
     */
    public function getRaptorStatus(string $datasetId): array
    {
        return $this->get("/datasets/{$datasetId}/trace_raptor");
    }

    // -------------------------------------------------------------------------
    // Health
    // -------------------------------------------------------------------------

    /**
     * Check if RAGFlow is reachable and responding.
     */
    public function healthCheck(): bool
    {
        try {
            $response = Http::timeout(10)
                ->withToken($this->apiKey)
                ->get($this->url('/datasets'), ['page' => 1, 'page_size' => 1]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::debug('RAGFlow healthCheck failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Circuit breaker
    // -------------------------------------------------------------------------

    public function isCircuitOpen(): bool
    {
        return (bool) Cache::get(self::CIRCUIT_BREAKER_KEY, false);
    }

    private function recordCircuitFailure(): void
    {
        $failures = (int) Cache::increment(self::CIRCUIT_FAILURE_KEY);

        if ($failures >= $this->circuitBreakerThreshold) {
            Cache::put(self::CIRCUIT_BREAKER_KEY, true, $this->circuitBreakerTtl);
            Cache::forget(self::CIRCUIT_FAILURE_KEY);
            Log::warning('RAGFlow circuit breaker opened', ['failures' => $failures]);
        }
    }

    private function resetCircuitFailures(): void
    {
        Cache::forget(self::CIRCUIT_FAILURE_KEY);
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/api/v1/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query = []): array
    {
        $response = Http::timeout($this->timeout)
            ->withToken($this->apiKey)
            ->get($this->url($path), $query);

        return $this->parseResponse($response, "GET {$path}");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $path, array $payload = []): array
    {
        $response = Http::timeout($this->timeout)
            ->withToken($this->apiKey)
            ->post($this->url($path), $payload);

        return $this->parseResponse($response, "POST {$path}");
    }

    private function delete(string $path): void
    {
        $response = Http::timeout($this->timeout)
            ->withToken($this->apiKey)
            ->delete($this->url($path));

        $this->parseResponse($response, "DELETE {$path}");
    }

    private function parseResponse(Response $response, string $context): array
    {
        if (! $response->successful()) {
            Log::debug('RAGFlow API error', [
                'context' => $context,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            throw new RAGFlowException(
                "RAGFlow {$context} returned HTTP {$response->status()}",
                $response->status(),
            );
        }

        $body = $response->json();

        if (is_array($body) && isset($body['code']) && $body['code'] !== 0) {
            throw new RAGFlowException(
                "RAGFlow {$context} error: ".($body['message'] ?? 'unknown error'),
            );
        }

        return $body['data'] ?? $body ?? [];
    }
}
