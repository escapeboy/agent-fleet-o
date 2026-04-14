<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Models\CompanyIntentScore;
use App\Domain\Signal\Models\Signal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use App\Mcp\Attributes\AssistantTool;

/**
 * MCP tool for querying composite buyer intent scores.
 *
 * Provides the FIRE-model composite intent score for a company or person,
 * along with score breakdown, threshold classification, and contributing signals.
 *
 * Scores are computed by the SignalStackingEngine from all signals for the
 * entity across all sources (ClearCue, GitHub, webhooks, etc.).
 */
#[IsReadOnly]
#[AssistantTool('read')]
class IntentScoreTool extends Tool
{
    protected string $name = 'intent_score_query';

    protected string $description = 'Query the composite buyer intent score for a company or person. Returns FIRE model dimensions (Fit, Intent, Engagement, Relationship), signal history, and threshold classification (hot/warm/lukewarm/cold). Use this to prioritise outreach and decide when to trigger experiments.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: get_score | list_hot_leads | get_signal_history')
                ->enum(['get_score', 'list_hot_leads', 'get_signal_history'])
                ->required(),
            'entity_key' => $schema->string()
                ->description('Stable identifier for the entity: LinkedIn URL, company domain, or website URL. Required for get_score and get_signal_history.'),
            'entity_type' => $schema->string()
                ->description('Entity type: company | person')
                ->enum(['company', 'person']),
            'threshold' => $schema->string()
                ->description('Minimum threshold for list_hot_leads: hot (80+) | warm (50+) | lukewarm (20+)')
                ->enum(['hot', 'warm', 'lukewarm']),
            'limit' => $schema->integer()
                ->description('Maximum number of results for list_hot_leads (default 20, max 100)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'action' => 'required|string|in:get_score,list_hot_leads,get_signal_history',
            'entity_key' => 'nullable|string|max:500',
            'entity_type' => 'nullable|string|in:company,person',
            'threshold' => 'nullable|string|in:hot,warm,lukewarm',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            return match ($validated['action']) {
                'get_score' => $this->getScore($validated),
                'list_hot_leads' => $this->listHotLeads($validated),
                'get_signal_history' => $this->getSignalHistory($validated),
            };
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }

    private function getScore(array $params): Response
    {
        $entityKey = $params['entity_key'] ?? null;
        if (! $entityKey) {
            return Response::error('entity_key is required for get_score');
        }

        $score = CompanyIntentScore::where('entity_key', $entityKey)->first();

        if (! $score) {
            return Response::text(json_encode([
                'entity_key' => $entityKey,
                'found' => false,
                'message' => 'No intent score computed yet. Score is calculated when signals arrive for this entity.',
            ]));
        }

        return Response::text(json_encode([
            'entity_key' => $entityKey,
            'entity_type' => $score->entity_type,
            'found' => true,
            'composite_score' => $score->composite_score,
            'intent_tag' => $score->intentTag(),
            'threshold' => $this->classifyThreshold($score->composite_score),
            'dimensions' => [
                'fit' => $score->fit_score,
                'intent' => $score->intent_score,
                'engagement' => $score->engagement_score,
                'relationship' => $score->relationship_score,
            ],
            'signal_count' => $score->signal_count,
            'signal_diversity' => $score->signal_diversity,
            'score_breakdown' => $score->score_breakdown,
            'last_scored_at' => $score->last_scored_at?->toIso8601String(),
            'recommendation' => $this->getRecommendation($score->composite_score),
        ]));
    }

    private function listHotLeads(array $params): Response
    {
        $minScore = match ($params['threshold'] ?? 'warm') {
            'hot' => 80,
            'warm' => 50,
            'lukewarm' => 20,
            default => 50,
        };

        $limit = min((int) ($params['limit'] ?? 20), 100);

        $scores = CompanyIntentScore::where('composite_score', '>=', $minScore)
            ->orderByDesc('composite_score')
            ->limit($limit)
            ->get()
            ->map(fn ($s) => [
                'entity_key' => $s->entity_key,
                'entity_type' => $s->entity_type,
                'composite_score' => $s->composite_score,
                'intent_tag' => $s->intentTag(),
                'signal_count' => $s->signal_count,
                'signal_diversity' => $s->signal_diversity,
                'last_scored_at' => $s->last_scored_at?->toIso8601String(),
            ]);

        return Response::text(json_encode([
            'leads' => $scores,
            'count' => $scores->count(),
            'min_score' => $minScore,
            'threshold_label' => $params['threshold'] ?? 'warm',
        ]));
    }

    private function getSignalHistory(array $params): Response
    {
        $entityKey = $params['entity_key'] ?? null;
        if (! $entityKey) {
            return Response::error('entity_key is required for get_signal_history');
        }

        $signals = Signal::where('source_identifier', $entityKey)
            ->where('source_type', '!=', 'intent_score')
            ->select(['id', 'source_type', 'score', 'tags', 'payload', 'received_at'])
            ->latest('received_at')
            ->limit(50)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'source_type' => $s->source_type,
                'signal_type' => is_array($s->payload) ? ($s->payload['signal_type'] ?? null) : null,
                'signal_category' => is_array($s->payload) ? ($s->payload['signal_category'] ?? null) : null,
                'score' => $s->score,
                'tags' => $s->tags,
                'received_at' => $s->received_at,
            ]);

        $intentScore = CompanyIntentScore::where('entity_key', $entityKey)->first();

        return Response::text(json_encode([
            'entity_key' => $entityKey,
            'current_score' => $intentScore?->composite_score,
            'intent_tag' => $intentScore?->intentTag(),
            'signal_history' => $signals,
            'signal_count' => $signals->count(),
        ]));
    }

    private function classifyThreshold(float $score): string
    {
        return match (true) {
            $score >= 80 => 'hot',
            $score >= 50 => 'warm',
            $score >= 20 => 'lukewarm',
            default => 'cold',
        };
    }

    private function getRecommendation(float $score): string
    {
        return match (true) {
            $score >= 80 => 'Immediate outreach recommended — company shows strong buying intent across multiple signals.',
            $score >= 50 => 'Enroll in BDR sequence — company is actively evaluating, timing is right for outreach.',
            $score >= 20 => 'Add to nurture flow — company is researching, not yet ready for direct outreach.',
            default => 'Monitor only — insufficient intent signals. Wait for more activity before engaging.',
        };
    }
}
