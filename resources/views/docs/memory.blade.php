<x-layouts.docs
    title="Memory & Knowledge"
    description="FleetQ provides two complementary knowledge systems — semantic Memory and a Knowledge Graph — that enrich agent context during execution and persist learnings across runs."
    page="memory"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Memory & Knowledge</h1>
    <p class="mt-4 text-gray-600">
        FleetQ provides two complementary knowledge systems that give agents persistent, searchable context beyond the
        current conversation window. Both systems are team-scoped and are automatically injected into agent prompts
        during experiment execution.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>An agent monitors competitor pricing daily. After each run it stores its findings
        in Memory. The next day's run retrieves those memories and only reports changes — no duplicate alerts,
        no context loss between runs.</em>
    </p>

    {{-- Two systems overview --}}
    <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-blue-100 bg-blue-50 p-5">
            <p class="font-semibold text-blue-900">Memory System</p>
            <p class="mt-1 text-sm text-blue-800">
                Vector-based semantic search powered by pgvector (1536-dimensional embeddings).
                Store observations, learnings, and facts. Retrieve by meaning, not exact keywords.
            </p>
        </div>
        <div class="rounded-xl border border-violet-100 bg-violet-50 p-5">
            <p class="font-semibold text-violet-900">Knowledge Graph</p>
            <p class="mt-1 text-sm text-violet-800">
                Entity-relationship facts stored in <code class="font-mono text-xs">kg_edges</code>.
                Source → target with typed facts and vector embeddings for semantic search across relationships.
            </p>
        </div>
    </div>

    {{-- Memory System --}}
    <h2 class="mt-12 text-xl font-bold text-gray-900">Memory System</h2>
    <p class="mt-2 text-sm text-gray-600">
        The memory system stores arbitrary text as 1536-dimensional vector embeddings using pgvector. Each memory
        belongs to a team and carries optional metadata (type, source experiment, tags). Retrieval uses cosine
        similarity so you can ask "what do we know about X?" in natural language.
    </p>

    <div class="mt-4 space-y-3">
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <p class="font-semibold text-gray-900">Store</p>
            <p class="mt-1 text-sm text-gray-600">
                Save observations, learnings, and context captured during agent runs. Memories persist across experiments
                and are available to any agent on the team.
            </p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <p class="font-semibold text-gray-900">Search</p>
            <p class="mt-1 text-sm text-gray-600">
                Natural language queries are embedded and matched by cosine similarity. Relevant memories surface
                even when the exact wording differs.
            </p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <p class="font-semibold text-gray-900">Auto-injection</p>
            <p class="mt-1 text-sm text-gray-600">
                The <code class="font-mono text-xs">InjectMemoryContext</code> pipeline middleware runs before every
                agent call. It embeds the current task description, searches for the top-k relevant memories, and
                prepends them to the system prompt automatically — no agent configuration required.
            </p>
        </div>
    </div>

    {{-- Knowledge Upload --}}
    <h2 class="mt-12 text-xl font-bold text-gray-900">Knowledge Upload</h2>
    <p class="mt-2 text-sm text-gray-600">
        Upload documents (PDFs, markdown files, plain text) as knowledge sources. FleetQ chunks each document,
        generates an embedding for every chunk, and stores them as memories tagged with the source document.
        Retrieved chunks are then available to all agents on the team just like hand-written memories.
    </p>

    <x-docs.code lang="bash" title="Upload via MCP tool">
# From Claude Code or any MCP client connected to FleetQ:
memory_upload_knowledge(
  name: "Q1 2025 Competitor Report",
  content: "...",          # full document text
  source: "manual_upload",
  chunk_size: 512          # tokens per chunk (optional, default 512)
)</x-docs.code>

    <x-docs.callout type="tip">
        Upload product documentation, past research reports, or domain reference material once and all
        future agents on the team will benefit from it without prompt engineering.
    </x-docs.callout>

    {{-- Knowledge Graph --}}
    <h2 class="mt-12 text-xl font-bold text-gray-900">Knowledge Graph</h2>
    <p class="mt-2 text-sm text-gray-600">
        The knowledge graph stores structured facts as directed edges between named entities. Each edge has a
        <em>source</em> entity, a <em>target</em> entity, and a human-readable <em>fact</em> string. Facts are
        also embedded with pgvector so you can find related facts by meaning, not just by entity name.
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Column</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-3 pl-4 pr-6 font-mono text-xs text-gray-900">source_entity</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">The subject of the fact (e.g. "Acme Corp")</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-mono text-xs text-gray-900">target_entity</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">The object of the fact (e.g. "Series B")</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-mono text-xs text-gray-900">fact</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Human-readable statement (e.g. "raised $20M in Series B in Jan 2025")</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-mono text-xs text-gray-900">fact_embedding</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">1536-dimensional HNSW vector for semantic search</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-mono text-xs text-gray-900">relation_type</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Optional label for the relationship (e.g. "funded_by", "acquired")</td>
                </tr>
            </tbody>
        </table>
    </div>

    <p class="mt-4 text-sm text-gray-600">
        The <code class="font-mono text-xs">InjectKnowledgeGraphContext</code> middleware runs after
        <code class="font-mono text-xs">InjectMemoryContext</code> in the AI pipeline. It embeds the current task
        and retrieves the top matching facts, injecting them as structured context into the agent prompt.
    </p>

    {{-- KG Operations --}}
    <h2 class="mt-12 text-xl font-bold text-gray-900">Knowledge Graph Operations</h2>

    <div class="mt-4 space-y-6">
        <div>
            <p class="font-semibold text-gray-900">kg_search — semantic search across all facts</p>
            <p class="mt-1 text-sm text-gray-600">
                Embed a query and return the most similar facts by cosine distance. Useful for open-ended
                discovery ("what do we know about Series B rounds?").
            </p>
            <x-docs.code lang="bash">
kg_search(query: "recent funding rounds", limit: 10)</x-docs.code>
        </div>

        <div>
            <p class="font-semibold text-gray-900">kg_entity_facts — get all facts about an entity</p>
            <p class="mt-1 text-sm text-gray-600">
                Retrieve every fact where the given string appears as either the source or target entity.
                Useful for building a complete picture of a specific company, person, or concept.
            </p>
            <x-docs.code lang="bash">
kg_entity_facts(entity: "Acme Corp")</x-docs.code>
        </div>

        <div>
            <p class="font-semibold text-gray-900">kg_add_fact — add a new fact</p>
            <p class="mt-1 text-sm text-gray-600">
                Agents (or humans via the MCP client) can write new facts into the graph during or after
                a run. The fact is embedded automatically on creation.
            </p>
            <x-docs.code lang="bash">
kg_add_fact(
  source_entity: "Acme Corp",
  target_entity: "Widget Pro",
  fact: "launched Widget Pro in March 2025",
  relation_type: "launched"
)</x-docs.code>
        </div>
    </div>

    <x-docs.callout type="info">
        Knowledge graph facts are team-scoped. Every agent on the team shares the same graph, so facts
        captured by one agent are immediately visible to all others.
    </x-docs.callout>

    {{-- Memory Browser --}}
    <h2 class="mt-12 text-xl font-bold text-gray-900">Memory Browser</h2>
    <p class="mt-2 text-sm text-gray-600">
        The <a href="/memory" class="text-primary-600 hover:underline">Memory Browser</a> at
        <code class="font-mono text-xs">/memory</code> lets you inspect, search, and delete memories from the UI.
        Use it to audit what your agents have learned, remove outdated entries, or verify that uploaded
        documents were chunked and stored correctly.
    </p>

    <div class="mt-4 space-y-2 text-sm text-gray-600">
        <div class="flex items-start gap-2">
            <span class="mt-0.5 inline-block h-1.5 w-1.5 flex-shrink-0 rounded-full bg-gray-400"></span>
            <span><strong>Search</strong> — enter a natural language query to find semantically similar memories</span>
        </div>
        <div class="flex items-start gap-2">
            <span class="mt-0.5 inline-block h-1.5 w-1.5 flex-shrink-0 rounded-full bg-gray-400"></span>
            <span><strong>Browse</strong> — paginated list of recent memories with metadata and source tags</span>
        </div>
        <div class="flex items-start gap-2">
            <span class="mt-0.5 inline-block h-1.5 w-1.5 flex-shrink-0 rounded-full bg-gray-400"></span>
            <span><strong>Delete</strong> — remove individual memories or bulk-clear by filter</span>
        </div>
        <div class="flex items-start gap-2">
            <span class="mt-0.5 inline-block h-1.5 w-1.5 flex-shrink-0 rounded-full bg-gray-400"></span>
            <span><strong>Stats</strong> — total memory count, storage size, and embedding coverage</span>
        </div>
    </div>

    {{-- MCP Tools --}}
    <h2 class="mt-12 text-xl font-bold text-gray-900">MCP Tools</h2>
    <p class="mt-2 text-sm text-gray-600">
        All memory and knowledge graph operations are available as MCP tools so agents and LLM clients
        (Claude Code, Cursor) can read and write knowledge programmatically.
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Tool</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">System</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-3 pl-4 pr-6 font-mono text-xs text-gray-900">memory_search</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">Memory</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Semantic similarity search over stored memories</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-mono text-xs text-gray-900">memory_list_recent</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">Memory</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">List recently added memories, optionally filtered by type or source</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-mono text-xs text-gray-900">memory_stats</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">Memory</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Return total count, storage size, and embedding coverage metrics</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-mono text-xs text-gray-900">memory_delete</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">Memory</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Delete a specific memory by ID</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-mono text-xs text-gray-900">memory_upload_knowledge</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">Memory</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Chunk, embed, and store a document as knowledge memories</td>
                </tr>
                <tr class="bg-gray-50/50">
                    <td class="py-3 pl-4 pr-6 font-mono text-xs text-gray-900">kg_search</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">Knowledge Graph</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Semantic search across all entity-relationship facts</td>
                </tr>
                <tr class="bg-gray-50/50">
                    <td class="py-3 pl-4 pr-6 font-mono text-xs text-gray-900">kg_entity_facts</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">Knowledge Graph</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Retrieve all facts where the given entity appears as source or target</td>
                </tr>
                <tr class="bg-gray-50/50">
                    <td class="py-3 pl-4 pr-6 font-mono text-xs text-gray-900">kg_add_fact</td>
                    <td class="py-3 pr-6 text-xs text-gray-600">Knowledge Graph</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Add a new source → target fact with optional relation type</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- API Endpoints --}}
    <h2 class="mt-12 text-xl font-bold text-gray-900">API Endpoints</h2>
    <p class="mt-2 text-sm text-gray-600">
        The Memory domain is fully accessible via the REST API under <code class="font-mono text-xs">/api/v1/memory</code>.
        All endpoints require a Sanctum bearer token. See the
        <a href="/docs/api" class="text-primary-600 hover:underline">OpenAPI reference</a> for full request/response schemas.
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-3 text-left font-semibold text-gray-700">Method</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Path</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-3 pl-4 pr-3">
                        <span class="inline-flex rounded bg-emerald-100 px-1.5 py-0.5 font-mono text-xs font-semibold text-emerald-700">GET</span>
                    </td>
                    <td class="py-3 pr-6 font-mono text-xs text-gray-900">/api/v1/memory</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Paginated list of memories (cursor pagination)</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-3">
                        <span class="inline-flex rounded bg-emerald-100 px-1.5 py-0.5 font-mono text-xs font-semibold text-emerald-700">GET</span>
                    </td>
                    <td class="py-3 pr-6 font-mono text-xs text-gray-900">/api/v1/memory/{id}</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Get a single memory by ID</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-3">
                        <span class="inline-flex rounded bg-blue-100 px-1.5 py-0.5 font-mono text-xs font-semibold text-blue-700">POST</span>
                    </td>
                    <td class="py-3 pr-6 font-mono text-xs text-gray-900">/api/v1/memory</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Create a new memory (text is embedded automatically)</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-3">
                        <span class="inline-flex rounded bg-red-100 px-1.5 py-0.5 font-mono text-xs font-semibold text-red-700">DELETE</span>
                    </td>
                    <td class="py-3 pr-6 font-mono text-xs text-gray-900">/api/v1/memory/{id}</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Delete a memory by ID</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-3">
                        <span class="inline-flex rounded bg-blue-100 px-1.5 py-0.5 font-mono text-xs font-semibold text-blue-700">POST</span>
                    </td>
                    <td class="py-3 pr-6 font-mono text-xs text-gray-900">/api/v1/memory/search</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Semantic search — pass <code class="font-mono text-xs">query</code> and optional <code class="font-mono text-xs">limit</code></td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-3">
                        <span class="inline-flex rounded bg-emerald-100 px-1.5 py-0.5 font-mono text-xs font-semibold text-emerald-700">GET</span>
                    </td>
                    <td class="py-3 pr-6 font-mono text-xs text-gray-900">/api/v1/memory/stats</td>
                    <td class="py-3 pr-4 text-xs text-gray-600">Return total count, storage usage, and embedding coverage</td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.code lang="bash" title="Example: semantic search via API">
curl -X POST https://your-fleetq-instance/api/v1/memory/search \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"query": "competitor pricing changes last quarter", "limit": 5}'</x-docs.code>

    <x-docs.callout type="warning">
        Deleting a memory is permanent. There is no soft-delete for memories. If you need to archive rather
        than remove, consider tagging memories with a status field in their metadata before deletion.
    </x-docs.callout>

</x-layouts.docs>
