<x-layouts.docs
    title="Knowledge Graph"
    description="Temporal fact storage with Personalized PageRank search, Louvain community detection, and two-pass gleaning extraction — a persistent world model for your agents."
    page="knowledge-graph"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Knowledge Graph</h1>
    <p class="mt-4 text-gray-600">
        The <strong>Knowledge Graph</strong> stores structured facts as directed edges
        (<em>source → relation → target</em>) with vector embeddings on each fact.
        Unlike flat <a href="{{ route('docs.show', 'memory') }}" class="text-primary-600 hover:underline">Memory</a>
        entries, the graph captures <em>relationships</em> — who knows whom, which company uses which tool,
        which event caused which outcome — and lets agents traverse and reason over those connections.
    </p>

    {{-- Core concepts --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Core concepts</h2>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">kg_edges</p>
            <p class="mt-1 text-sm text-gray-600">
                Each fact is a row in <code class="font-mono text-xs">kg_edges</code>:
                a <em>source entity</em>, a <em>relation</em>, a <em>target entity</em>,
                an optional <em>context</em>, a <em>timestamp</em>, and a
                <code class="font-mono text-xs">fact_embedding vector(1536)</code> indexed with HNSW.
                Facts are team-scoped and soft-deleted.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Personalized PageRank search</p>
            <p class="mt-1 text-sm text-gray-600">
                Graph search uses PPR (α=0.85) over a 3-hop subgraph centred on the query entity,
                combined with cosine similarity on fact embeddings. This returns contextually relevant
                facts that naive vector search would miss.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Two-pass gleaning</p>
            <p class="mt-1 text-sm text-gray-600">
                <code class="font-mono text-xs">ExtractKnowledgeEdgesAction</code> runs a second LLM pass
                to catch facts missed in the first pass, then deduplicates by
                source + relation + target before storing. This significantly improves recall on
                dense source documents.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Louvain communities</p>
            <p class="mt-1 text-sm text-gray-600">
                A nightly job runs Louvain community detection (pure PHP) over the graph,
                groups related entities into topics, generates LLM summaries per community,
                and indexes those summaries with pgvector HNSW for fast community-level search.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Entity merging</p>
            <p class="mt-1 text-sm text-gray-600">
                <code class="font-mono text-xs">DetectDuplicateEntitiesAction</code> finds semantically
                equivalent entities (e.g. "OpenAI" vs "Open AI Inc.") and proposes merges.
                <code class="font-mono text-xs">MergeEntitiesAction</code> re-points all edges to the
                canonical entity. Runs daily at 04:30.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Context injection</p>
            <p class="mt-1 text-sm text-gray-600">
                The <code class="font-mono text-xs">InjectKnowledgeGraphContext</code> middleware sits
                in the agent execution pipeline. Before each LLM call it queries the graph for facts
                relevant to the current task and prepends them to the system prompt.
            </p>
        </div>
    </div>

    {{-- MCP tools --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">MCP tools</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Tool</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">kg_search</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Semantic + PPR graph search. Returns ranked facts with source entities.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">kg_entity_facts</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Retrieve all facts for a specific entity (outgoing and incoming edges).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">kg_add_fact</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Store a new fact edge (source, relation, target, context, timestamp).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">kg_community_search</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Search across Louvain community summaries for topic-level context.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">kg_suggest_merges</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List pending duplicate-entity merge proposals.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">kg_merge_entities</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Apply an entity merge — re-points all edges to the canonical entity.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.callout type="tip">
        The Knowledge Graph is distinct from
        <a href="{{ route('docs.show', 'memory') }}" class="text-primary-600 hover:underline">Memory</a>.
        Memory stores unstructured text snippets (episodic, semantic). The Knowledge Graph stores
        typed, directed facts that support graph traversal and relationship reasoning.
        Use both together for the richest agent context.
    </x-docs.callout>

    {{-- Scheduled jobs --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Scheduled maintenance</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Job</th>
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Schedule</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Purpose</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">BuildKgCommunitiesAction</td>
                    <td class="py-2.5 pl-4 pr-6 text-xs text-gray-600">Daily 02:45</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Louvain community detection + LLM summaries + HNSW index rebuild.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">DetectDuplicateEntitiesAction</td>
                    <td class="py-2.5 pl-4 pr-6 text-xs text-gray-600">Daily 04:30</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Find and propose entity merges for review.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Related --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Related concepts</h2>
    <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-gray-600">
        <li><a href="{{ route('docs.show', 'memory') }}" class="text-primary-600 hover:underline">Memory & Knowledge</a> — episodic and semantic memory; use alongside the graph.</li>
        <li><a href="{{ route('docs.show', 'agents') }}" class="text-primary-600 hover:underline">Agents</a> — agents with KG context automatically receive relevant facts at inference time.</li>
        <li><a href="{{ route('docs.show', 'signals') }}" class="text-primary-600 hover:underline">Signals</a> — inbound signals can trigger KG fact extraction via skill pipelines.</li>
    </ul>
</x-layouts.docs>
