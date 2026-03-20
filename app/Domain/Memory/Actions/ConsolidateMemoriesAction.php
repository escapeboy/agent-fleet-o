<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Memory\Enums\MemoryVisibility;
use App\Domain\Memory\Models\Memory;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

/**
 * Consolidates similar memories for a single agent using greedy centroid clustering.
 *
 * Memories older than the exclusion window are clustered by cosine similarity.
 * Clusters of 3+ memories are merged into one consolidated memory via LLM summarization.
 */
class ConsolidateMemoriesAction
{
    private const CONSOLIDATE_PROMPT = <<<'PROMPT'
You are a memory consolidator. Merge these related facts into ONE concise statement that preserves all unique information. Drop redundancies. Keep specific details (dates, numbers, names).

Facts:
%s

Output: One consolidated statement only, no preamble.
PROMPT;

    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    /**
     * @return array{clusters_formed: int, memories_consolidated: int, memories_created: int}
     */
    public function execute(string $agentId, string $teamId): array
    {
        if (! config('memory.consolidation.enabled', true)) {
            return ['clusters_formed' => 0, 'memories_consolidated' => 0, 'memories_created' => 0];
        }

        $minMemories = config('memory.consolidation.min_memories_per_agent', 50);
        $minClusterSize = config('memory.consolidation.min_cluster_size', 3);
        $similarityThreshold = config('memory.consolidation.similarity_threshold', 0.85);
        $excludeNewerThanDays = config('memory.consolidation.exclude_newer_than_days', 7);

        // Check if agent has enough memories to consolidate
        $totalCount = Memory::withoutGlobalScopes()
            ->where('agent_id', $agentId)
            ->where('team_id', $teamId)
            ->count();

        if ($totalCount < $minMemories) {
            return ['clusters_formed' => 0, 'memories_consolidated' => 0, 'memories_created' => 0];
        }

        // Load candidate memories (older than exclusion window, not already consolidated sources)
        $cutoff = now()->subDays($excludeNewerThanDays);
        $candidates = Memory::withoutGlobalScopes()
            ->where('agent_id', $agentId)
            ->where('team_id', $teamId)
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('embedding')
            ->orderByDesc('importance')
            ->get();

        if ($candidates->count() < $minClusterSize) {
            return ['clusters_formed' => 0, 'memories_consolidated' => 0, 'memories_created' => 0];
        }

        // Greedy centroid clustering
        $assigned = collect();
        $clusters = [];

        foreach ($candidates as $seed) {
            if ($assigned->contains($seed->id)) {
                continue;
            }

            // Find similar unassigned memories
            $cluster = collect([$seed]);
            $assigned->push($seed->id);

            foreach ($candidates as $candidate) {
                if ($assigned->contains($candidate->id)) {
                    continue;
                }

                try {
                    $similarity = DB::selectOne(
                        'SELECT 1 - (?::vector <=> ?::vector) AS similarity',
                        [$seed->embedding, $candidate->embedding],
                    );

                    if ($similarity && $similarity->similarity >= $similarityThreshold) {
                        $cluster->push($candidate);
                        $assigned->push($candidate->id);
                    }
                } catch (\Throwable) {
                    // pgvector not available, skip similarity check
                    continue;
                }
            }

            if ($cluster->count() >= $minClusterSize) {
                $clusters[] = $cluster;
            }
        }

        // Consolidate each cluster
        $totalConsolidated = 0;
        $totalCreated = 0;

        foreach ($clusters as $cluster) {
            try {
                $this->consolidateCluster($cluster, $agentId, $teamId);
                $totalConsolidated += $cluster->count();
                $totalCreated++;
            } catch (\Throwable $e) {
                Log::warning('ConsolidateMemoriesAction: cluster consolidation failed', [
                    'agent_id' => $agentId,
                    'cluster_size' => $cluster->count(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($totalCreated > 0) {
            Log::info('ConsolidateMemoriesAction: consolidation complete', [
                'agent_id' => $agentId,
                'clusters_formed' => count($clusters),
                'memories_consolidated' => $totalConsolidated,
                'memories_created' => $totalCreated,
            ]);
        }

        return [
            'clusters_formed' => count($clusters),
            'memories_consolidated' => $totalConsolidated,
            'memories_created' => $totalCreated,
        ];
    }

    /**
     * Merge a cluster of similar memories into one consolidated memory.
     */
    private function consolidateCluster(Collection $cluster, string $agentId, string $teamId): void
    {
        $model = config('memory.consolidation.model', 'claude-haiku-4-5');

        // Build the facts list for the LLM
        $factsList = $cluster->map(fn ($m, $i) => ($i + 1).'. '.$m->content)->implode("\n");

        $response = $this->gateway->complete(new AiRequestDTO(
            provider: 'anthropic',
            model: $model,
            systemPrompt: 'You are a memory consolidator. Output only the merged fact.',
            userPrompt: sprintf(self::CONSOLIDATE_PROMPT, $factsList),
            maxTokens: 256,
            teamId: $teamId,
            agentId: $agentId,
            purpose: 'memory.consolidate',
            temperature: 0.1,
        ));

        $consolidatedContent = trim($response->content);
        if ($consolidatedContent === '') {
            return;
        }

        // Generate embedding for consolidated content
        $embeddingResponse = Prism::embeddings()
            ->using(config('memory.embedding_provider', 'openai'), config('memory.embedding_model', 'text-embedding-3-small'))
            ->fromInput($consolidatedContent)
            ->asEmbeddings();

        $embedding = '['.implode(',', $embeddingResponse->embeddings[0]->embedding).']';

        // Compute aggregate scores
        $maxImportance = $cluster->max('importance') ?? 0.5;
        $avgConfidence = $cluster->avg('confidence') ?? 1.0;
        $sumRetrievals = $cluster->sum('retrieval_count');
        $allTags = $cluster->pluck('tags')->flatten()->unique()->values()->all();
        $sourceIds = $cluster->pluck('id')->all();

        // Use the most permissive visibility from the cluster
        $visibilityOrder = [MemoryVisibility::Team, MemoryVisibility::Project, MemoryVisibility::Private];
        $bestVisibility = MemoryVisibility::Private;
        foreach ($visibilityOrder as $vis) {
            if ($cluster->contains(fn ($m) => $m->visibility === $vis)) {
                $bestVisibility = $vis;
                break;
            }
        }

        DB::transaction(function () use (
            $teamId, $agentId, $consolidatedContent, $embedding,
            $maxImportance, $avgConfidence, $sumRetrievals, $allTags,
            $sourceIds, $bestVisibility, $cluster,
        ) {
            // Create consolidated memory
            Memory::create([
                'team_id' => $teamId,
                'agent_id' => $agentId,
                'project_id' => $cluster->first()->project_id,
                'content' => $consolidatedContent,
                'embedding' => $embedding,
                'content_hash' => hash('sha256', mb_strtolower(trim($consolidatedContent))),
                'metadata' => ['source_ids' => $sourceIds, 'consolidated_at' => now()->toIso8601String()],
                'source_type' => 'consolidated',
                'confidence' => round($avgConfidence, 4),
                'importance' => $maxImportance,
                'retrieval_count' => $sumRetrievals,
                'tags' => $allTags,
                'visibility' => $bestVisibility,
            ]);

            // Delete original memories
            Memory::withoutGlobalScopes()
                ->whereIn('id', $sourceIds)
                ->delete();
        });
    }
}
