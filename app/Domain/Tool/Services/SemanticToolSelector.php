<?php

namespace App\Domain\Tool\Services;

use App\Domain\Tool\Models\ToolEmbedding;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

class SemanticToolSelector
{
    /**
     * Minimum number of PrismPHP tools before semantic filtering kicks in.
     * Below this threshold, all tools are returned unfiltered.
     */
    public static function threshold(): int
    {
        return (int) config('tools.semantic_filter_threshold', 15);
    }

    /**
     * Search tool embeddings by semantic similarity and return matching prism_tool_names.
     *
     * @param  array<string>  $toolIds  Tool model IDs to search within
     * @return Collection<int, string> Collection of prism_tool_name values
     */
    public function searchToolNames(
        string $query,
        string $teamId,
        array $toolIds,
        int $limit = 12,
        float $threshold = 0.75,
    ): Collection {
        if (DB::getDriverName() !== 'pgsql') {
            return collect();
        }

        try {
            $queryEmbedding = $this->generateEmbedding($query);
        } catch (\Throwable $e) {
            Log::warning('SemanticToolSelector: embedding generation failed, returning empty', [
                'error' => $e->getMessage(),
            ]);

            return collect();
        }

        return ToolEmbedding::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('team_id', $teamId)->orWhereNull('team_id'))
            ->whereIn('tool_id', $toolIds)
            ->whereNotNull('embedding')
            ->whereRaw('(1 - (embedding <=> ?::vector)) >= ?', [$queryEmbedding, $threshold])
            ->orderByRaw('embedding <=> ?::vector', [$queryEmbedding])
            ->limit($limit)
            ->pluck('prism_tool_name');
    }

    /**
     * Generate and store embeddings for all PrismPHP tools produced by a Tool model.
     *
     * @param  array<array{name: string, description: string}>  $toolDefs  PrismPHP tool name+description pairs
     */
    public function embedToolDefinitions(string $toolId, ?string $teamId, array $toolDefs): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            return 0;
        }

        $count = 0;

        foreach ($toolDefs as $def) {
            $textContent = $def['name'].': '.$def['description'];

            try {
                $embedding = $this->generateEmbedding($textContent);
            } catch (\Throwable $e) {
                Log::warning('SemanticToolSelector: failed to embed tool definition', [
                    'tool_id' => $toolId,
                    'prism_tool_name' => $def['name'],
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            ToolEmbedding::withoutGlobalScopes()->updateOrCreate(
                [
                    'tool_id' => $toolId,
                    'prism_tool_name' => $def['name'],
                ],
                [
                    'team_id' => $teamId,
                    'text_content' => $textContent,
                    'embedding' => $embedding,
                ],
            );

            $count++;
        }

        return $count;
    }

    /**
     * Remove all embeddings for a tool.
     */
    public function removeToolEmbeddings(string $toolId): int
    {
        return ToolEmbedding::withoutGlobalScopes()
            ->where('tool_id', $toolId)
            ->delete();
    }

    private function generateEmbedding(string $text): string
    {
        $model = config('tools.embedding_model', 'text-embedding-3-small');
        $provider = config('tools.embedding_provider', 'openai');

        $response = Prism::embeddings()
            ->using($provider, $model)
            ->fromInput($text)
            ->asEmbeddings();

        $vector = $response->embeddings[0]->embedding;

        return '['.implode(',', $vector).']';
    }
}
