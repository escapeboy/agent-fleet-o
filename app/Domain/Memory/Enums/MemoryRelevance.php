<?php

namespace App\Domain\Memory\Enums;

/**
 * A coarse relevance label for a retrieved memory.
 *
 * Borrowed from the Oracle "RAG → memory" pattern: return high/standard/low to
 * the agent instead of a bare cosine score. Models calibrate poorly on raw
 * floats but use coarse labels well. Bands are calibrated to
 * text-embedding-3-small (relevant query/passage pairs ≈ 0.45-0.62 cosine).
 */
enum MemoryRelevance: string
{
    case High = 'high';
    case Standard = 'standard';
    case Low = 'low';

    /**
     * Map a cosine similarity (0-1) to a relevance label using the configured
     * bands. Returns null when no similarity is available (e.g. a lexical-only
     * or knowledge-graph hit with no vector signal) so callers can omit the
     * label rather than fabricate one.
     */
    public static function fromCosine(?float $similarity): ?self
    {
        if ($similarity === null) {
            return null;
        }

        $high = (float) config('memory.relevance_tiers.high', 0.55);
        $standard = (float) config('memory.relevance_tiers.standard', 0.45);

        return match (true) {
            $similarity >= $high => self::High,
            $similarity >= $standard => self::Standard,
            default => self::Low,
        };
    }
}
