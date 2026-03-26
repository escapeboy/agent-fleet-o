<?php

declare(strict_types=1);

namespace App\Domain\Tool\Services;

use App\Domain\Tool\Models\Tool;
use Illuminate\Support\Collection;

/**
 * 3-stage RAG-style tool selector that progressively filters a collection of Tool models
 * before falling back to the expensive pgvector semantic search.
 *
 * Stage 1 — keyword match: exact token overlap against name + description (O(n), no I/O)
 * Stage 2 — fuzzy name match: similar_text() on tool names (O(n), no I/O)
 * Stage 3 — semantic fallback: pgvector cosine similarity via SemanticToolSelector (I/O + embedding call)
 *
 * Early exit happens whenever a stage produces ≥ $minConfidentResults matches that represent
 * < 30 % of the total tool set (i.e. the result is specific rather than vague).
 */
class ToolRagSelector
{
    /**
     * English stopwords excluded from keyword extraction.
     *
     * @var array<string>
     */
    private const STOPWORDS = [
        'a', 'an', 'the', 'is', 'to', 'of', 'in', 'for', 'on', 'at', 'by',
        'it', 'its', 'be', 'as', 'or', 'and', 'with', 'from', 'that', 'this',
        'are', 'was', 'were', 'has', 'have', 'had', 'do', 'does', 'did',
        'not', 'but', 'if', 'so', 'up', 'out', 'no', 'can', 'all', 'get',
        'use', 'using', 'used', 'my', 'me', 'we', 'you', 'he', 'she', 'they',
        'i', 'will', 'would', 'could', 'should', 'may', 'might', 'about',
        'into', 'than', 'then', 'when', 'what', 'which', 'who', 'how', 'any',
    ];

    /**
     * Maximum number of tools returned from the fuzzy name match stage.
     */
    private const FUZZY_RESULT_CAP = 12;

    /**
     * Minimum similar_text() percentage (0–100) for a tool to qualify in stage 2.
     */
    private const FUZZY_MIN_SCORE = 60.0;

    /**
     * Maximum fraction of total tools a confident result set may represent.
     * Results covering ≥ 30 % of all tools are considered too vague.
     */
    private const HIGH_CONFIDENCE_MAX_RATIO = 0.30;

    public function __construct(private SemanticToolSelector $semanticSelector) {}

    /**
     * Select the most relevant tools for the given natural-language query.
     *
     * Stages are attempted in order; the first stage that produces a high-confidence
     * result set short-circuits the pipeline.  If all cheap stages fail, the result
     * falls through to SemanticToolSelector (requires $teamId to be provided).
     *
     * @param  Collection<int, Tool>  $tools  Pool of active Tool models
     * @param  string  $query  Natural-language task / query string
     * @param  int  $minConfidentResults  Minimum number of matches required to exit early
     * @param  string|null  $teamId  Team UUID passed to SemanticToolSelector (stage 3); if null, stage 3 is skipped and $tools is returned unfiltered
     * @param  array<string>  $toolIds  Tool model IDs passed to SemanticToolSelector; derived from $tools when empty
     * @return Collection<int, Tool>
     */
    public function select(
        Collection $tools,
        string $query,
        int $minConfidentResults = 3,
        ?string $teamId = null,
        array $toolIds = [],
    ): Collection {
        if ($tools->isEmpty() || $query === '') {
            return $tools;
        }

        // Stage 1: keyword match
        $keywords = $this->extractKeywords($query);
        if (! empty($keywords)) {
            $keywordMatches = $this->keywordMatch($tools, $keywords);
            if ($this->isHighConfidence($keywordMatches, $tools, $minConfidentResults)) {
                return $keywordMatches;
            }
        }

        // Stage 2: fuzzy name match
        $fuzzyMatches = $this->fuzzyNameMatch($tools, $query);
        if ($this->isHighConfidence($fuzzyMatches, $tools, $minConfidentResults)) {
            return $fuzzyMatches->take(self::FUZZY_RESULT_CAP)->values();
        }

        // Stage 3: semantic fallback via pgvector
        if ($teamId !== null) {
            return $this->semanticFallback($tools, $query, $teamId, $toolIds);
        }

        return $tools;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Extract meaningful keyword tokens from a natural-language query.
     *
     * Handles space/punctuation splitting, snake_case expansion, and camelCase splitting.
     *
     * @return array<string>
     */
    private function extractKeywords(string $query): array
    {
        $lower = strtolower($query);

        // Expand snake_case: "get_repo" → "get repo"
        $expanded = str_replace('_', ' ', $lower);

        // Expand camelCase: "getRepo" → "get Repo" → "get repo"
        $expanded = (string) preg_replace('/([a-z])([A-Z])/', '$1 $2', $expanded);

        // Split on whitespace and non-alphanumeric characters
        $tokens = (array) preg_split('/[^a-z0-9]+/i', strtolower($expanded), -1, PREG_SPLIT_NO_EMPTY);

        $keywords = [];
        foreach ($tokens as $token) {
            $token = strtolower((string) $token);
            if ($token !== '' && ! in_array($token, self::STOPWORDS, true) && strlen($token) > 1) {
                $keywords[] = $token;
            }
        }

        return array_values(array_unique($keywords));
    }

    /**
     * Score and filter tools by keyword overlap against name + description.
     *
     * Each tool receives a score equal to the number of keywords found in
     * its combined name-and-description text.  Results are sorted by score descending.
     *
     * @param  Collection<int, Tool>  $tools
     * @param  array<string>  $keywords
     * @return Collection<int, Tool>
     */
    private function keywordMatch(Collection $tools, array $keywords): Collection
    {
        /** @var array<string, int> $scores Map of tool key => match count */
        $scores = [];

        $matched = $tools->filter(function ($tool) use ($keywords, &$scores): bool {
            $haystack = strtolower($tool->name.' '.($tool->description ?? ''));
            $count = 0;
            foreach ($keywords as $kw) {
                if (str_contains($haystack, $kw)) {
                    $count++;
                }
            }
            if ($count > 0) {
                $scores[$tool->getKey()] = $count;

                return true;
            }

            return false;
        });

        return $matched->sortByDesc(fn ($tool) => $scores[$tool->getKey()] ?? 0)->values();
    }

    /**
     * Score tools by name similarity using PHP's similar_text() function.
     *
     * Only tools whose name similarity exceeds FUZZY_MIN_SCORE are included.
     * Results are sorted by similarity score descending.
     *
     * @param  Collection<int, Tool>  $tools
     * @return Collection<int, Tool>
     */
    private function fuzzyNameMatch(Collection $tools, string $query): Collection
    {
        $lowerQuery = strtolower($query);

        /** @var array<string, float> $scores Map of tool key => similarity percentage */
        $scores = [];

        $matched = $tools->filter(function ($tool) use ($lowerQuery, &$scores): bool {
            similar_text($lowerQuery, strtolower($tool->name), $percent);
            if ($percent > self::FUZZY_MIN_SCORE) {
                $scores[$tool->getKey()] = $percent;

                return true;
            }

            return false;
        });

        return $matched->sortByDesc(fn ($tool) => $scores[$tool->getKey()] ?? 0.0)->values();
    }

    /**
     * Determine whether a match set is "high confidence":
     * - at least $minRequired tools matched, AND
     * - the match count is less than 30 % of total tools (i.e. not too broad).
     *
     * @param  Collection<int, Tool>  $matched
     * @param  Collection<int, Tool>  $allTools
     */
    private function isHighConfidence(Collection $matched, Collection $allTools, int $minRequired): bool
    {
        $matchCount = $matched->count();
        $totalCount = $allTools->count();

        if ($matchCount < $minRequired) {
            return false;
        }

        if ($totalCount === 0) {
            return false;
        }

        return ($matchCount / $totalCount) < self::HIGH_CONFIDENCE_MAX_RATIO;
    }

    /**
     * Fall back to SemanticToolSelector (pgvector) and re-map the returned
     * prism tool names back to Tool models by checking each tool's name.
     *
     * Because a single Tool model can produce multiple PrismPHP tools with names
     * derived from its own name, we include a Tool when any matched prism name
     * contains the lowercased tool name as a substring.
     *
     * If semantic search returns no matches or fails, the full collection is returned.
     *
     * @param  Collection<int, Tool>  $tools
     * @param  array<string>  $toolIds  Explicit tool model IDs; defaults to all IDs in $tools
     * @return Collection<int, Tool>
     */
    private function semanticFallback(
        Collection $tools,
        string $query,
        string $teamId,
        array $toolIds = [],
    ): Collection {
        $ids = ! empty($toolIds) ? $toolIds : $tools->pluck('id')->toArray();

        $limit = (int) config('tools.semantic_filter_limit', 12);
        $similarityThreshold = (float) config('tools.semantic_filter_similarity', 0.75);

        $matchedNames = $this->semanticSelector->searchToolNames(
            $query,
            $teamId,
            $ids,
            $limit,
            $similarityThreshold,
        );

        if ($matchedNames->isEmpty()) {
            return $tools;
        }

        // Build a set of matched name fragments for O(1) lookup
        $nameSet = $matchedNames->map(fn (string $n) => strtolower($n))->flip()->toArray();

        $filtered = $tools->filter(function ($tool) use ($nameSet): bool {
            $lowerName = strtolower($tool->name);
            // Direct hit: a prism tool name exactly matches tool model name
            if (isset($nameSet[$lowerName])) {
                return true;
            }
            // Substring hit: a prism tool name contains the model name (e.g. "github_list_repos")
            foreach (array_keys($nameSet) as $prismName) {
                if (str_contains($prismName, $lowerName)) {
                    return true;
                }
            }

            return false;
        });

        // Fall back to all tools when semantic search matched nothing after mapping
        return $filtered->isEmpty() ? $tools : $filtered->values();
    }
}
