<?php

namespace Tests\Unit\Infrastructure\AI\LoopDetection;

use App\Infrastructure\AI\LoopDetection\SimilarityScorer;
use PHPUnit\Framework\TestCase;

class SimilarityScorerTest extends TestCase
{
    public function test_identical_text_scores_one(): void
    {
        $a = SimilarityScorer::bigrams('the quick brown fox jumps');
        $b = SimilarityScorer::bigrams('the quick brown fox jumps');

        $this->assertSame(1.0, SimilarityScorer::jaccard($a, $b));
    }

    public function test_disjoint_text_scores_zero(): void
    {
        $a = SimilarityScorer::bigrams('alpha beta gamma delta');
        $b = SimilarityScorer::bigrams('one two three four');

        $this->assertSame(0.0, SimilarityScorer::jaccard($a, $b));
    }

    public function test_near_identical_text_scores_high(): void
    {
        $a = SimilarityScorer::bigrams('summarize the quarterly earnings report for acme corp');
        $b = SimilarityScorer::bigrams('summarize the quarterly earnings report for acme inc');

        $score = SimilarityScorer::jaccard($a, $b);

        $this->assertGreaterThan(0.6, $score);
        $this->assertLessThan(1.0, $score);
    }

    public function test_two_empty_sets_are_identical(): void
    {
        $this->assertSame(1.0, SimilarityScorer::jaccard([], []));
    }

    public function test_one_empty_set_scores_zero(): void
    {
        $this->assertSame(0.0, SimilarityScorer::jaccard(['a b'], []));
    }

    public function test_bigrams_are_unicode_aware_and_cased(): void
    {
        // Cyrillic must survive normalization (control-char strip, not ASCII strip).
        $bigrams = SimilarityScorer::bigrams('Обработи Данните Сега');

        $this->assertContains('обработи данните', $bigrams);
        $this->assertContains('данните сега', $bigrams);
    }

    public function test_token_cap_bounds_bigram_set(): void
    {
        $text = str_repeat('word ', 1000);

        $bigrams = SimilarityScorer::bigrams($text, 50);

        // 50 identical tokens collapse to a single distinct bigram.
        $this->assertLessThanOrEqual(50, count($bigrams));
    }
}
