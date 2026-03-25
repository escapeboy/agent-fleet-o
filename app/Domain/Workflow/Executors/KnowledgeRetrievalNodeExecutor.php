<?php

namespace App\Domain\Workflow\Executors;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Knowledge\Actions\SearchKnowledgeAction;
use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Workflow\Contracts\NodeExecutorInterface;
use App\Domain\Workflow\Models\WorkflowNode;

/**
 * Queries a KnowledgeBase via semantic (pgvector) search and returns top-K chunks.
 * Zero LLM cost — pure vector similarity search.
 *
 * Config shape:
 * {
 *   "knowledge_base_id": "uuid",
 *   "query_template": "{{context}}",
 *   "top_k": 5,
 *   "similarity_threshold": 0.75
 * }
 *
 * Output port: chunks → array of {content, source, score}
 */
class KnowledgeRetrievalNodeExecutor implements NodeExecutorInterface
{
    use InterpolatesTemplates;

    public function __construct(
        private readonly SearchKnowledgeAction $searchAction,
    ) {}

    public function execute(WorkflowNode $node, PlaybookStep $step, Experiment $experiment): array
    {
        $config = $this->parseConfig($node->config);
        $context = $this->buildStepContext($step, $experiment);

        $knowledgeBaseId = $config['knowledge_base_id'] ?? null;

        if (! $knowledgeBaseId) {
            throw new \InvalidArgumentException('Knowledge Retrieval node: knowledge_base_id is required');
        }

        // Ensure the knowledge base belongs to the team (TeamScope)
        $kb = KnowledgeBase::where('id', $knowledgeBaseId)
            ->where('team_id', $experiment->team_id)
            ->first();

        if (! $kb) {
            throw new \InvalidArgumentException("Knowledge Retrieval node: knowledge base {$knowledgeBaseId} not found for this team");
        }

        $queryTemplate = $config['query_template'] ?? '{{context}}';
        $query = $this->interpolate($queryTemplate, $context);

        if (empty($query)) {
            return ['chunks' => [], 'count' => 0];
        }

        $topK = (int) ($config['top_k'] ?? 5);

        $chunks = $this->searchAction->execute($knowledgeBaseId, $query, $topK);

        // Apply similarity threshold filter if configured
        $threshold = isset($config['similarity_threshold'])
            ? (float) $config['similarity_threshold']
            : null;

        if ($threshold !== null) {
            $chunks = array_values(array_filter(
                $chunks,
                fn (array $chunk) => ($chunk['score'] ?? 1.0) >= $threshold,
            ));
        }

        return [
            'chunks' => $chunks,
            'count' => count($chunks),
            'query' => $query,
        ];
    }
}
