<?php

namespace Tests\Unit\Domain\Memory\Services;

use App\Domain\Memory\Services\RetrievalMetrics;
use PHPUnit\Framework\TestCase;

class RetrievalMetricsTest extends TestCase
{
    public function test_recall_at_k_perfect_partial_and_zero(): void
    {
        $this->assertSame(1.0, RetrievalMetrics::recallAtK(['a', 'b'], ['a', 'b', 'c'], 10));
        $this->assertSame(0.5, RetrievalMetrics::recallAtK(['a', 'b'], ['a', 'x', 'y'], 10));
        $this->assertSame(0.0, RetrievalMetrics::recallAtK(['a', 'b'], ['x', 'y'], 10));
    }

    public function test_recall_at_k_respects_cutoff(): void
    {
        // 'b' is retrieved, but beyond k=2 — must not count.
        $this->assertSame(0.5, RetrievalMetrics::recallAtK(['a', 'b'], ['a', 'x', 'b'], 2));
    }

    public function test_mrr_positions(): void
    {
        $this->assertSame(1.0, RetrievalMetrics::mrr(['a'], ['a', 'b', 'c']));
        $this->assertEqualsWithDelta(1 / 3, RetrievalMetrics::mrr(['c'], ['a', 'b', 'c']), 1e-9);
        $this->assertSame(0.0, RetrievalMetrics::mrr(['z'], ['a', 'b', 'c']));
    }

    public function test_ndcg_at_k(): void
    {
        // Perfect ranking: both relevant docs at the top.
        $this->assertEqualsWithDelta(1.0, RetrievalMetrics::ndcgAtK(['a', 'b'], ['a', 'b', 'x'], 10), 1e-9);

        // Single relevant doc at rank 2 of an ideal rank 1: DCG=1/log2(3), IDCG=1.
        $expected = (1 / log(3, 2)) / 1.0;
        $this->assertEqualsWithDelta($expected, RetrievalMetrics::ndcgAtK(['a'], ['x', 'a'], 10), 1e-9);

        // Nothing retrieved.
        $this->assertSame(0.0, RetrievalMetrics::ndcgAtK(['a'], [], 10));
    }

    public function test_empty_relevant_list_yields_null_not_division_error(): void
    {
        $this->assertNull(RetrievalMetrics::recallAtK([], ['a'], 10));
        $this->assertNull(RetrievalMetrics::mrr([], ['a']));
        $this->assertNull(RetrievalMetrics::ndcgAtK([], ['a'], 10));
    }
}
