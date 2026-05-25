<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Services\SignalRelevanceScorer;
use App\Infrastructure\AI\Services\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignalRelevanceScorerTest extends TestCase
{
    use RefreshDatabase;

    private function scorer(): SignalRelevanceScorer
    {
        return new SignalRelevanceScorer(new EmbeddingService);
    }

    public function test_cosine_of_identical_vectors_is_one(): void
    {
        $this->assertEqualsWithDelta(1.0, $this->scorer()->cosine([1.0, 2.0, 3.0], [1.0, 2.0, 3.0]), 1e-9);
    }

    public function test_cosine_of_orthogonal_vectors_is_zero(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->scorer()->cosine([1.0, 0.0], [0.0, 1.0]), 1e-9);
    }

    public function test_cosine_with_zero_vector_is_zero(): void
    {
        $this->assertSame(0.0, $this->scorer()->cosine([0.0, 0.0], [1.0, 1.0]));
    }

    public function test_combine_blends_signals_into_explained_score(): void
    {
        $result = $this->scorer()->combine(simLiked: 1.0, simDisliked: -1.0, novelty: 5, llmScore: 1.0);

        // preference clamps to 1.0, novelty 1.0, llm 1.0 → 0.6 + 0.25 + 0.15 = 1.0
        $this->assertEqualsWithDelta(1.0, $result['score'], 1e-9);
        $this->assertSame(
            ['preference_similarity', 'novelty', 'llm_quality'],
            array_column($result['breakdown'], 'signal'),
        );
    }

    public function test_combine_handles_null_novelty_and_llm(): void
    {
        // Neutral similarity (liked == disliked → preference 0.5), no novelty, no llm.
        $result = $this->scorer()->combine(simLiked: 0.5, simDisliked: 0.5, novelty: null, llmScore: null);

        $this->assertEqualsWithDelta(0.3, $result['score'], 1e-9);
        $this->assertSame(0.0, $result['breakdown'][1]['contribution']);
        $this->assertSame(0.0, $result['breakdown'][2]['contribution']);
    }

    public function test_score_is_noop_without_pgvector(): void
    {
        $team = Team::factory()->create();
        $signal = Signal::create([
            'team_id' => $team->id,
            'source_type' => 'hacker_news',
            'source_identifier' => 'hacker_news:top',
            'status' => SignalStatus::Received,
            'content_hash' => md5('rel-'.uniqid()),
            'received_at' => now(),
            'payload' => ['title' => 'Something', 'body' => 'A reasonably long body of text for scoring purposes.'],
            'tags' => ['hacker_news'],
        ]);

        $this->assertNull($this->scorer()->score($signal));
        $this->assertNull($signal->fresh()->learned_relevance_score);
    }
}
