<?php

namespace App\Domain\Agent\Pipeline\Middleware;

use App\Domain\Agent\Pipeline\AgentExecutionContext;
use App\Domain\KnowledgeGraph\Services\TemporalKnowledgeGraphService;
use Closure;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

/**
 * Injects relevant temporal knowledge graph facts into the agent's system prompt.
 * Runs parallel to InjectMemoryContext, providing structured entity relationship context.
 */
class InjectKnowledgeGraphContext
{
    public function __construct(
        private readonly TemporalKnowledgeGraphService $kgService,
    ) {}

    public function handle(AgentExecutionContext $ctx, Closure $next): AgentExecutionContext
    {
        try {
            $inputText = is_array($ctx->input)
                ? ($ctx->input['task'] ?? $ctx->input['content'] ?? $ctx->input['query'] ?? implode(' ', array_filter($ctx->input)))
                : (string) $ctx->input;

            if (mb_strlen(trim($inputText)) < 10) {
                return $next($ctx);
            }

            $queryEmbedding = $this->generateEmbedding($inputText);
            if (! $queryEmbedding) {
                return $next($ctx);
            }

            $kgContext = $this->kgService->buildContext($ctx->teamId, $queryEmbedding);

            if ($kgContext) {
                $ctx->systemPromptParts[] = $kgContext;
            }
        } catch (\Throwable $e) {
            // Graceful degradation — KG context is additive, never blocking
            Log::warning('InjectKnowledgeGraphContext: Failed to inject KG context', [
                'agent_id' => $ctx->agent->id,
                'error'    => $e->getMessage(),
            ]);
        }

        return $next($ctx);
    }

    private function generateEmbedding(string $text): ?string
    {
        try {
            $model = config('memory.embedding_model', 'text-embedding-3-small');

            $response = Prism::embeddings()
                ->using('openai', $model)
                ->fromInput(mb_substr($text, 0, 1000))
                ->asEmbeddings();

            $vector = $response->embeddings[0]->embedding;

            return '['.implode(',', $vector).']';
        } catch (\Throwable) {
            return null;
        }
    }
}
