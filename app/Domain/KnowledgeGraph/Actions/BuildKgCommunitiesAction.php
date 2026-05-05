<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeGraph\Actions;

use App\Domain\KnowledgeGraph\Models\KgCommunity;
use App\Domain\KnowledgeGraph\Services\LouvainCommunityDetector;
use App\Domain\Signal\Models\Entity;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Support\LlmDefaults;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Prism\Prism\Facades\Prism;

class BuildKgCommunitiesAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly LouvainCommunityDetector $detector,
    ) {}

    /**
     * Rebuild community clusters for a team using the Louvain algorithm.
     */
    public function execute(string $teamId, int $minCommunitySize = 2): void
    {
        $entities = Entity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->select(['id', 'name', 'type', 'mention_count'])
            ->get();

        if ($entities->count() < 3) {
            return;
        }

        $entityIds = $entities->pluck('id')->toArray();

        $edges = DB::table('kg_edges')
            ->where('team_id', $teamId)
            ->whereNull('invalid_at')
            ->where('edge_type', 'relates_to')
            ->whereIn('source_entity_id', $entityIds)
            ->whereIn('target_entity_id', $entityIds)
            ->select(['source_entity_id', 'target_entity_id'])
            ->get()
            ->map(fn ($e) => [$e->source_entity_id, $e->target_entity_id])
            ->toArray();

        if (count($edges) < 2) {
            return;
        }

        $communityMap = $this->detector->detect($entityIds, $edges);

        // Group entities by community
        $groups = [];
        foreach ($communityMap as $entityId => $communityId) {
            $groups[$communityId][] = $entityId;
        }

        // Delete old communities for this team
        KgCommunity::where('team_id', $teamId)->delete();

        $entityLookup = $entities->keyBy('id');
        $builtCount = 0;

        foreach ($groups as $communityId => $memberIds) {
            if (count($memberIds) < $minCommunitySize) {
                continue;
            }

            // Top 5 entities by mention_count
            $members = collect($memberIds)
                ->map(fn ($id) => $entityLookup->get($id))
                ->filter()
                ->sortByDesc('mention_count');

            $topEntities = $members->take(5)->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->name,
                'type' => $e->type,
                'mentions' => $e->mention_count,
            ])->values()->toArray();

            // Representative facts for the summary prompt
            $representativeFacts = DB::table('kg_edges')
                ->where('team_id', $teamId)
                ->whereNull('invalid_at')
                ->where('edge_type', 'relates_to')
                ->whereIn('source_entity_id', $memberIds)
                ->whereIn('target_entity_id', $memberIds)
                ->limit(5)
                ->pluck('fact')
                ->toArray();

            // Generate LLM summary
            $entityList = $members->map(fn ($e) => "{$e->name} ({$e->type})")->join(', ');
            $factsText = implode('; ', $representativeFacts);

            [$label, $summary] = $this->generateSummary($teamId, $entityList, $factsText);

            // Generate embedding for summary
            $embedding = $summary ? $this->generateEmbedding($summary) : null;

            $data = [
                'team_id' => $teamId,
                'label' => $label,
                'summary' => $summary,
                'entity_ids' => $memberIds,
                'size' => count($memberIds),
                'top_entities' => $topEntities,
            ];

            if ($embedding && Schema::hasColumn('kg_communities', 'summary_embedding')) {
                $data['summary_embedding'] = $embedding;
            }

            KgCommunity::create($data);
            $builtCount++;
        }

        Log::info('BuildKgCommunitiesAction: communities built', [
            'team_id' => $teamId,
            'count' => $builtCount,
        ]);
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function generateSummary(string $teamId, string $entityList, string $factsText): array
    {
        try {
            $request = new AiRequestDTO(
                provider: LlmDefaults::provider(),
                model: LlmDefaults::model(),
                systemPrompt: 'You are summarizing a cluster of related entities from a knowledge graph. Generate a concise label (max 5 words) and a 2-3 sentence summary describing what connects these entities. Return ONLY valid JSON: {"label": "...", "summary": "..."}',
                userPrompt: "Entities: {$entityList}.\n\nTop relationships: {$factsText}",
                maxTokens: 200,
                teamId: $teamId,
                purpose: 'kg_community_summary',
                temperature: 0.3,
            );

            $response = $this->gateway->complete($request);
            $content = trim($response->content ?? '');

            if (str_starts_with($content, '```')) {
                $content = preg_replace('/^```\w*\n?/', '', $content);
                $content = preg_replace('/\n?```$/', '', $content);
            }

            $decoded = json_decode(trim($content), true);
            if (is_array($decoded) && isset($decoded['label'], $decoded['summary'])) {
                return [$decoded['label'], $decoded['summary']];
            }
        } catch (\Throwable $e) {
            Log::warning('BuildKgCommunitiesAction: summary generation failed', ['error' => $e->getMessage()]);
        }

        return [null, null];
    }

    private function generateEmbedding(string $text): ?array
    {
        try {
            $model = config('memory.embedding_model', 'text-embedding-3-small');

            $response = Prism::embeddings()
                ->using(config('memory.embedding_provider', 'openai'), $model)
                ->fromInput($text)
                ->asEmbeddings();

            return $response->embeddings[0]->embedding;
        } catch (\Throwable $e) {
            Log::warning('BuildKgCommunitiesAction: embedding generation failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
