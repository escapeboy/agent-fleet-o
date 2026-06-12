<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Embedding Model
    |--------------------------------------------------------------------------
    |
    | The model used to generate embeddings for agent memory storage
    | and retrieval. text-embedding-3-small is the most cost-effective.
    |
    */
    'embedding_provider' => env('MEMORY_EMBEDDING_PROVIDER', 'openai'),

    'embedding_model' => env('MEMORY_EMBEDDING_MODEL', 'text-embedding-3-small'),

    // Embedding backend driver. 'cloud' (default) routes through Prism to the
    // provider/model above. 'local' is the extension point for a future
    // on-box embedding backend (FFI/embedding.cpp); it is not implemented yet
    // and selecting it throws LocalEmbeddingNotConfiguredException. Resolved
    // via EmbeddingProviderInterface in AppServiceProvider.
    'embedding_driver' => env('MEMORY_EMBEDDING_DRIVER', 'cloud'),

    // Hard input cap for a single embedding call, in tokens. OpenAI's
    // text-embedding-3-* models reject inputs over 8192 tokens with a 400
    // ("maximum input length is 8192 tokens"); query text is truncated to this
    // limit before the embedding call so a long query degrades gracefully
    // instead of failing the whole retrieval.
    'embedding_max_input_tokens' => (int) env('MEMORY_EMBEDDING_MAX_INPUT_TOKENS', 8192),

    /*
    |--------------------------------------------------------------------------
    | Embedding Dimensions
    |--------------------------------------------------------------------------
    |
    | Number of dimensions for the embedding vectors. Must match the
    | vector column size in the memories table migration.
    |
    */
    'embedding_dimensions' => 1536,

    /*
    |--------------------------------------------------------------------------
    | Memory Nudge (Hermes-inspired closed learning loop)
    |--------------------------------------------------------------------------
    |
    | When a team opts in (settings['memory_nudge_enabled'] === true), agents
    | whose un-memorialized activity reaches this threshold receive an in-prompt
    | reminder to persist durable learnings via the memory store tool. This
    | complements the post-hoc distill/consolidate crons; it does not write
    | memories itself. Off by default at the team level.
    |
    */
    'nudge' => [
        'execution_threshold' => (int) env('MEMORY_NUDGE_EXECUTION_THRESHOLD', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Memory Extraction Model
    |--------------------------------------------------------------------------
    |
    | The cheap/fast model used by the extraction family — ExtractAndStore,
    | ExtractFailureLesson, ExtractSuccessPattern — to distil durable facts,
    | lessons, and patterns from completed runs.
    |
    | Format: "provider/model" (e.g. "anthropic/claude-haiku-4-5"). The provider
    | prefix binds the model to the provider that actually serves it, so the
    | model name is never POSTed to a foreign provider (the cause of the 400
    | "model 'claude-haiku-4-5' does not exist" on gateways that don't expose
    | Anthropic models). Deployments routed through an OpenAI-compatible bridge
    | should override this with a model their gateway exposes, e.g.
    | MEMORY_EXTRACTION_MODEL=openai/gpt-4o-mini.
    |
    */
    'extraction' => [
        'model' => env('MEMORY_EXTRACTION_MODEL', 'anthropic/claude-haiku-4-5'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Memory TTL (Days)
    |--------------------------------------------------------------------------
    |
    | How many days to keep memories before pruning. Set to 0 to disable.
    |
    */
    'ttl_days' => (int) env('MEMORY_TTL_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Top-K Retrieval
    |--------------------------------------------------------------------------
    |
    | Number of most relevant memories to retrieve for context injection.
    |
    */
    'top_k' => (int) env('MEMORY_TOP_K', 5),

    /*
    |--------------------------------------------------------------------------
    | Similarity Threshold
    |--------------------------------------------------------------------------
    |
    | Minimum cosine similarity score (0-1) for memories to be included.
    | Higher values mean stricter matching.
    |
    | Calibrated against text-embedding-3-small via memory:benchmark-retrieval
    | (2026-06-12): relevant query/passage pairs score cosine ~0.45-0.62 with
    | this model, so the old 0.7 default excluded essentially all real matches
    | (Recall@10 0.10). The sweep plateaus around 0.35-0.45; 0.4 captures the
    | relevant band while leaving precision headroom. Re-run the benchmark and
    | retune this if the embedding model changes.
    |
    */
    'similarity_threshold' => (float) env('MEMORY_SIMILARITY_THRESHOLD', 0.4),

    /*
    |--------------------------------------------------------------------------
    | Max Chunk Size
    |--------------------------------------------------------------------------
    |
    | Maximum character count per memory chunk when storing execution outputs.
    |
    */
    'max_chunk_size' => 2000,

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch for the memory system. When disabled, no memories
    | are stored or retrieved during agent execution.
    |
    */
    'enabled' => (bool) env('MEMORY_ENABLED', true),

    'auto_flush_enabled' => (bool) env('MEMORY_AUTO_FLUSH_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Composite Scoring Weights
    |--------------------------------------------------------------------------
    |
    | Weights for the composite memory scoring formula:
    | score = semantic_weight * similarity + recency_weight * decay + importance_weight * importance
    | Weights should sum to 1.0 for normalized scoring.
    |
    */
    'scoring' => [
        'semantic_weight' => (float) env('MEMORY_SEMANTIC_WEIGHT', 0.5),
        'recency_weight' => (float) env('MEMORY_RECENCY_WEIGHT', 0.3),
        'importance_weight' => (float) env('MEMORY_IMPORTANCE_WEIGHT', 0.2),
        'half_life_days' => (int) env('MEMORY_HALF_LIFE_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Write Gate (Semantic Deduplication)
    |--------------------------------------------------------------------------
    |
    | Before storing a new memory, check for near-duplicates using hash and
    | vector similarity. skip_threshold: exact semantic match (discard).
    | update_threshold: similar enough to merge (LLM-assisted).
    |
    */
    'write_gate' => [
        'enabled' => (bool) env('MEMORY_WRITE_GATE_ENABLED', true),
        'skip_threshold' => (float) env('MEMORY_SKIP_THRESHOLD', 0.95),
        'update_threshold' => (float) env('MEMORY_UPDATE_THRESHOLD', 0.85),
        'hash_dedup' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Memory Consolidation
    |--------------------------------------------------------------------------
    |
    | Periodic merging of similar memories into consolidated summaries.
    | Reduces noise while preserving signal. Runs as a daily batch job.
    |
    */
    'consolidation' => [
        'enabled' => (bool) env('MEMORY_CONSOLIDATION_ENABLED', true),
        'min_memories_per_agent' => 50,
        'min_cluster_size' => 3,
        'similarity_threshold' => 0.85,
        'exclude_newer_than_days' => 7,
        'model' => env('MEMORY_CONSOLIDATION_MODEL', 'anthropic/claude-haiku-4-5'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Distillation
    |--------------------------------------------------------------------------
    |
    | Nightly job (`memory:distill-events`) that reads each team's recent
    | audit-entry stream and distils it into a few durable, high-signal
    | facts stored as `events_digest` memories — borrowed from CraftBot's
    | EVENT_UNPROCESSED.md -> MEMORY.md pass. Runs before consolidation so
    | fresh digests get merged the same night.
    |
    */
    'distillation' => [
        'enabled' => (bool) env('MEMORY_DISTILLATION_ENABLED', true),
        'window_hours' => 24,
        'max_events' => 200,
        'provider' => 'anthropic',
        'model' => env('MEMORY_DISTILLATION_MODEL', 'anthropic/claude-haiku-4-5'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Importance-Weighted Pruning
    |--------------------------------------------------------------------------
    |
    | Pruning considers both age and importance instead of pure TTL.
    | High-importance or frequently-retrieved memories are protected.
    |
    */
    'pruning' => [
        'score_threshold' => (float) env('MEMORY_PRUNE_SCORE_THRESHOLD', 0.05),
        'max_ttl_days' => (int) env('MEMORY_MAX_TTL_DAYS', 365),
        'protect_importance_above' => 0.8,
        'protect_retrieval_above' => 10,
        // Cap on total memories per agent — lowest-scoring are evicted when exceeded
        'max_per_agent' => (int) env('MEMORY_MAX_PER_AGENT', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Unified Search (RRF Fusion)
    |--------------------------------------------------------------------------
    |
    | Reciprocal Rank Fusion across vector memory, knowledge graph, and keyword
    | search. Higher weights = more influence on final ranking.
    |
    */
    'unified_search' => [
        'enabled' => (bool) env('MEMORY_UNIFIED_SEARCH', true),
        'kg_weight' => 2.0,
        'vector_weight' => 1.0,
        'keyword_weight' => 0.5,
        'rrf_k' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Deep Relevance Judgment (tier-2)
    |--------------------------------------------------------------------------
    |
    | Optional second pass over retrieved memories: a cheap LLM re-scores each
    | candidate for task-specific relevance and drops sub-threshold ones before
    | injection (contextrie's shallow→deep cascade). Trades one cheap call for a
    | tighter, smaller injected context. Off by default; only runs when the
    | candidate set is at least 'min_candidates'. Fails open.
    |
    */
    'deep_judgment' => [
        'enabled' => (bool) env('MEMORY_DEEP_JUDGMENT_ENABLED', false),
        'model' => env('MEMORY_DEEP_JUDGMENT_MODEL', 'anthropic/claude-haiku-4-5'),
        'threshold' => (float) env('MEMORY_DEEP_JUDGMENT_THRESHOLD', 0.5),
        'min_candidates' => (int) env('MEMORY_DEEP_JUDGMENT_MIN_CANDIDATES', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Contextual RAG
    |--------------------------------------------------------------------------
    |
    | When enabled, each stored chunk gets an LLM-generated 64-token context
    | prepended before re-embedding (Anthropic Contextual Retrieval technique).
    | Significantly improves retrieval for ambiguous or out-of-context chunks.
    | Cost: ~1 Haiku call per chunk at index time.
    |
    */
    'contextual_rag' => [
        'enabled' => (bool) env('MEMORY_CONTEXTUAL_RAG_ENABLED', false),
        'model' => env('MEMORY_CONTEXTUAL_RAG_MODEL', 'anthropic/claude-haiku-4-5'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Visibility & Cross-Agent Sharing
    |--------------------------------------------------------------------------
    |
    | Controls when private memories are auto-promoted to project scope.
    | A memory needs both minimum retrievals AND importance to be shared.
    |
    */
    'visibility' => [
        'auto_promote_retrievals' => 3,
        'auto_promote_min_importance' => 0.7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Proposal Workflow (Knowledge Nominations)
    |--------------------------------------------------------------------------
    |
    | When extractors_enabled is true, ExtractSuccessPattern/FailureLesson
    | route through the Proposed tier instead of writing directly to the
    | curated tier. A heuristic auditor (AuditMemoryProposalsAction) then
    | auto-approves obvious wins, auto-rejects obvious noise, and queues the
    | rest for human review.
    |
    | Existing installs keep extractors_enabled=false → no behavior change.
    |
    */
    'proposal_workflow' => [
        'extractors_enabled' => (bool) env('MEMORY_PROPOSAL_WORKFLOW', false),
        'auto_approve_threshold' => (float) env('MEMORY_PROPOSAL_AUTO_APPROVE', 0.85),
        'min_content_length' => (int) env('MEMORY_PROPOSAL_MIN_LENGTH', 30),
        'min_confidence' => (float) env('MEMORY_PROPOSAL_MIN_CONFIDENCE', 0.3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cross-Corpus Contradiction Scan (RoBrain Synthesis)
    |--------------------------------------------------------------------------
    |
    | A scheduled batch job (`memory:detect-contradictions`) that scans the
    | whole memory corpus for pairs of beliefs that reverse each other —
    | contradictions that only emerge later, across sessions, which the
    | per-write dedup gate cannot see. Flagged pairs are surfaced for human
    | review in the Memory Browser.
    |
    | The scheduled run is OFF by default (it costs one Haiku call per team
    | per run). The manual MCP scan ignores `enabled` — it is opt-in already.
    | Candidate pairs are memories whose cosine similarity falls in the band
    | [min_similarity, max_similarity]: similar enough to be about the same
    | thing, not so similar they are duplicates the write gate already merged.
    |
    */
    'contradiction_scan' => [
        'enabled' => (bool) env('MEMORY_CONTRADICTION_SCAN', false),
        'model' => env('MEMORY_CONTRADICTION_MODEL', 'anthropic/claude-haiku-4-5'),
        'min_similarity' => (float) env('MEMORY_CONTRADICTION_MIN_SIMILARITY', 0.55),
        'max_similarity' => (float) env('MEMORY_CONTRADICTION_MAX_SIMILARITY', 0.92),
        'candidate_limit' => (int) env('MEMORY_CONTRADICTION_CANDIDATE_LIMIT', 60),
        'max_pairs' => (int) env('MEMORY_CONTRADICTION_MAX_PAIRS', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Evidence citations (Web IQ borrow)
    |--------------------------------------------------------------------------
    | When enabled, injected memory context items carry a stable [[mem:id]]
    | citation token and prefer the passage-level chunk_context over full
    | content — optimising information density per token while keeping outputs
    | attributable. Off by default → injected context is byte-for-byte legacy.
    */
    'evidence_citations' => [
        'enabled' => (bool) env('MEMORY_EVIDENCE_CITATIONS_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Execution memory auto-capture + drift monitoring
    |--------------------------------------------------------------------------
    */
    'auto_capture' => (bool) env('MEMORY_AUTO_CAPTURE', true),
    'drift_threshold' => (float) env('MEMORY_DRIFT_THRESHOLD', 0.30),

];
