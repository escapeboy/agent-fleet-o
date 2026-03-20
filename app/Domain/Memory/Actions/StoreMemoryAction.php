<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Memory\Enums\MemoryVisibility;
use App\Domain\Memory\Enums\WriteGateDecision;
use App\Domain\Memory\Models\Memory;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

class StoreMemoryAction
{
    private const MERGE_MODEL = 'claude-haiku-4-5';

    private const MERGE_PROMPT = <<<'PROMPT'
Merge these two related facts into one concise statement that preserves all unique details.
Drop redundancies. Keep specific details (dates, numbers, names).

Existing: %s
New: %s

Output: merged fact only, no preamble.
PROMPT;

    public function __construct(
        private readonly ?AiGatewayInterface $gateway = null,
    ) {}

    /**
     * Chunk content, generate embeddings, and store as Memory records.
     * Write gate deduplicates before inserting.
     *
     * @return Memory[]
     */
    public function execute(
        string $teamId,
        string $agentId,
        string $content,
        string $sourceType,
        ?string $projectId = null,
        ?string $sourceId = null,
        array $metadata = [],
        float $confidence = 1.0,
        float $importance = 0.5,
        array $tags = [],
        ?MemoryVisibility $visibility = null,
    ): array {
        if (! config('memory.enabled', true)) {
            return [];
        }

        if (empty(trim($content))) {
            return [];
        }

        // Determine visibility from source type if not explicitly set
        $visibility ??= $this->resolveVisibility($sourceType);

        $chunks = $this->chunkContent($content);
        $memories = [];

        foreach ($chunks as $chunk) {
            try {
                $memory = $this->storeChunk(
                    $teamId, $agentId, $chunk, $sourceType,
                    $projectId, $sourceId, $metadata, $confidence,
                    $importance, $tags, $visibility,
                );

                if ($memory) {
                    $memories[] = $memory;
                }
            } catch (\Throwable $e) {
                Log::warning('StoreMemoryAction: Failed to store memory chunk', [
                    'agent_id' => $agentId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $memories;
    }

    /**
     * Store a single chunk with write gate deduplication.
     */
    private function storeChunk(
        string $teamId,
        string $agentId,
        string $chunk,
        string $sourceType,
        ?string $projectId,
        ?string $sourceId,
        array $metadata,
        float $confidence,
        float $importance,
        array $tags,
        MemoryVisibility $visibility,
    ): ?Memory {
        $contentHash = hash('sha256', mb_strtolower(trim($chunk)));
        $embedding = $this->generateEmbedding($chunk);

        // Write gate: check for duplicates before inserting
        $decision = $this->evaluateWriteGate($teamId, $agentId, $contentHash, $embedding);

        return match ($decision->decision) {
            WriteGateDecision::Skip => $this->handleSkip($decision->existingMemory),
            WriteGateDecision::Update => $this->handleUpdate(
                $decision->existingMemory, $chunk, $embedding,
                $teamId, $agentId, $confidence, $importance, $tags,
            ),
            WriteGateDecision::Add => $this->handleAdd(
                $teamId, $agentId, $chunk, $embedding, $contentHash,
                $sourceType, $projectId, $sourceId, $metadata,
                $confidence, $importance, $tags, $visibility,
            ),
        };
    }

    /**
     * Two-stage write gate: hash check → semantic similarity.
     */
    private function evaluateWriteGate(
        string $teamId,
        string $agentId,
        string $contentHash,
        string $embedding,
    ): WriteGateResult {
        if (! config('memory.write_gate.enabled', true)) {
            return new WriteGateResult(WriteGateDecision::Add);
        }

        // Stage 1: Hash dedup (exact match)
        if (config('memory.write_gate.hash_dedup', true)) {
            $existing = Memory::withoutGlobalScopes()
                ->where('agent_id', $agentId)
                ->where('team_id', $teamId)
                ->where('content_hash', $contentHash)
                ->first();

            if ($existing) {
                Log::debug('WriteGate: SKIP (hash match)', ['memory_id' => $existing->id]);

                return new WriteGateResult(WriteGateDecision::Skip, $existing);
            }
        }

        // Stage 2: Semantic similarity (pgvector ANN)
        $skipThreshold = config('memory.write_gate.skip_threshold', 0.95);
        $updateThreshold = config('memory.write_gate.update_threshold', 0.85);

        try {
            $neighbor = Memory::withoutGlobalScopes()
                ->where('agent_id', $agentId)
                ->where('team_id', $teamId)
                ->whereNotNull('embedding')
                ->selectRaw('*, 1 - (embedding <=> ?) AS similarity', [$embedding])
                ->orderByRaw('embedding <=> ?', [$embedding])
                ->limit(1)
                ->first();

            if ($neighbor && $neighbor->similarity >= $skipThreshold) {
                Log::debug('WriteGate: SKIP (semantic)', [
                    'memory_id' => $neighbor->id,
                    'similarity' => round($neighbor->similarity, 4),
                ]);

                return new WriteGateResult(WriteGateDecision::Skip, $neighbor);
            }

            if ($neighbor && $neighbor->similarity >= $updateThreshold) {
                Log::debug('WriteGate: UPDATE (semantic merge)', [
                    'memory_id' => $neighbor->id,
                    'similarity' => round($neighbor->similarity, 4),
                ]);

                return new WriteGateResult(WriteGateDecision::Update, $neighbor);
            }
        } catch (\Throwable $e) {
            // pgvector not available (e.g., SQLite tests) — fall through to ADD
            Log::debug('WriteGate: semantic check failed, falling through to ADD', [
                'error' => $e->getMessage(),
            ]);
        }

        return new WriteGateResult(WriteGateDecision::Add);
    }

    private function handleSkip(?Memory $existing): ?Memory
    {
        // Touch last_accessed_at to signal the duplicate was seen again
        if ($existing) {
            $existing->update(['last_accessed_at' => now()]);
        }

        return $existing;
    }

    private function handleUpdate(
        ?Memory $existing,
        string $newContent,
        string $newEmbedding,
        string $teamId,
        string $agentId,
        float $newConfidence,
        float $newImportance,
        array $newTags,
    ): ?Memory {
        if (! $existing) {
            return null;
        }

        // Try LLM-assisted merge if gateway is available
        $mergedContent = $this->mergeContent($existing->content, $newContent, $teamId, $agentId);

        if ($mergedContent && $mergedContent !== $existing->content) {
            $mergedEmbedding = $this->generateEmbedding($mergedContent);
            $mergedHash = hash('sha256', mb_strtolower(trim($mergedContent)));

            $existing->update([
                'content' => $mergedContent,
                'embedding' => $mergedEmbedding,
                'content_hash' => $mergedHash,
                'confidence' => max($existing->confidence, $newConfidence),
                'importance' => max($existing->importance ?? 0.5, $newImportance),
                'tags' => array_values(array_unique(array_merge($existing->tags ?? [], $newTags))),
                'last_accessed_at' => now(),
            ]);
        } else {
            // Fallback: just bump scores without changing content
            $existing->update([
                'confidence' => max($existing->confidence, $newConfidence),
                'importance' => max($existing->importance ?? 0.5, $newImportance),
                'tags' => array_values(array_unique(array_merge($existing->tags ?? [], $newTags))),
                'last_accessed_at' => now(),
            ]);
        }

        return $existing->fresh();
    }

    private function handleAdd(
        string $teamId,
        string $agentId,
        string $chunk,
        string $embedding,
        string $contentHash,
        string $sourceType,
        ?string $projectId,
        ?string $sourceId,
        array $metadata,
        float $confidence,
        float $importance,
        array $tags,
        MemoryVisibility $visibility,
    ): Memory {
        return Memory::create([
            'team_id' => $teamId,
            'agent_id' => $agentId,
            'project_id' => $projectId,
            'content' => $chunk,
            'embedding' => $embedding,
            'content_hash' => $contentHash,
            'metadata' => $metadata,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'confidence' => $confidence,
            'importance' => $importance,
            'tags' => $tags,
            'visibility' => $visibility,
        ]);
    }

    /**
     * LLM-assisted merge of two related facts.
     */
    private function mergeContent(string $existing, string $new, string $teamId, string $agentId): ?string
    {
        if (! $this->gateway) {
            return null;
        }

        try {
            $response = $this->gateway->complete(new AiRequestDTO(
                provider: 'anthropic',
                model: self::MERGE_MODEL,
                systemPrompt: 'You are a memory consolidator. Output only the merged fact.',
                userPrompt: sprintf(self::MERGE_PROMPT, $existing, $new),
                maxTokens: 256,
                teamId: $teamId,
                agentId: $agentId,
                purpose: 'memory.merge',
                temperature: 0.1,
            ));

            $merged = trim($response->content);

            return $merged !== '' ? $merged : null;
        } catch (\Throwable $e) {
            Log::debug('WriteGate: merge failed, keeping existing content', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Determine default visibility from source type.
     */
    private function resolveVisibility(string $sourceType): MemoryVisibility
    {
        return match ($sourceType) {
            'document', 'manual_upload' => MemoryVisibility::Team,
            'experiment' => MemoryVisibility::Project,
            default => MemoryVisibility::Private,
        };
    }

    /**
     * Split content into chunks of configurable max size.
     *
     * @return string[]
     */
    private function chunkContent(string $content): array
    {
        $maxSize = config('memory.max_chunk_size', 2000);

        if (strlen($content) <= $maxSize) {
            return [$content];
        }

        $chunks = [];
        $paragraphs = preg_split('/\n\n+/', $content);
        $currentChunk = '';

        foreach ($paragraphs as $paragraph) {
            if (strlen($currentChunk) + strlen($paragraph) + 2 > $maxSize) {
                if ($currentChunk !== '') {
                    $chunks[] = trim($currentChunk);
                }
                if (strlen($paragraph) > $maxSize) {
                    $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph);
                    $currentChunk = '';
                    foreach ($sentences as $sentence) {
                        if (strlen($currentChunk) + strlen($sentence) + 1 > $maxSize) {
                            if ($currentChunk !== '') {
                                $chunks[] = trim($currentChunk);
                            }
                            $currentChunk = $sentence;
                        } else {
                            $currentChunk .= ($currentChunk ? ' ' : '').$sentence;
                        }
                    }
                } else {
                    $currentChunk = $paragraph;
                }
            } else {
                $currentChunk .= ($currentChunk ? "\n\n" : '').$paragraph;
            }
        }

        if (trim($currentChunk) !== '') {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * Generate embedding vector using PrismPHP.
     *
     * @return string Vector string for pgvector (e.g. "[0.1,0.2,...]")
     */
    private function generateEmbedding(string $text): string
    {
        $model = config('memory.embedding_model', 'text-embedding-3-small');

        $response = Prism::embeddings()
            ->using(config('memory.embedding_provider', 'openai'), $model)
            ->fromInput($text)
            ->asEmbeddings();

        $vector = $response->embeddings[0]->embedding;

        return '['.implode(',', $vector).']';
    }
}

/**
 * Internal value object for write gate evaluation result.
 */
class WriteGateResult
{
    public function __construct(
        public readonly WriteGateDecision $decision,
        public readonly ?Memory $existingMemory = null,
    ) {}
}
