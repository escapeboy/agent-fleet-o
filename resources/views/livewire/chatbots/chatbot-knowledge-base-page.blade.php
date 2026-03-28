<div>
    {{-- Flash messages --}}
    @if(session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('message') }}</div>
    @endif
    @if(session()->has('error'))
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    {{-- Back link --}}
    <div class="mb-4">
        <a href="{{ route('chatbots.show', $chatbot) }}" class="text-sm text-primary-600 hover:text-primary-800">
            &larr; Back to {{ $chatbot->name }}
        </a>
    </div>

    {{-- Knowledge Sources --}}
    <div class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
            <div>
                <h3 class="text-sm font-semibold text-gray-900">Knowledge Sources</h3>
                <p class="mt-0.5 text-xs text-gray-500">Documents and URLs used to answer questions.</p>
            </div>
            <button wire:click="$set('showAddForm', true)"
                class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                Add Source
            </button>
        </div>

        {{-- Add source form --}}
        @if($showAddForm)
            <div class="border-b border-gray-200 bg-gray-50 px-6 py-4">
                <div class="space-y-4">
                    <div class="flex gap-4">
                        <div class="flex-1">
                            <x-form-input wire:model="sourceName" label="Name" placeholder="E.g. Product FAQ" />
                        </div>
                        <div>
                            <x-form-select wire:model.live="sourceType" label="Type">
                                <option value="url">Web URL</option>
                                <option value="sitemap">Sitemap</option>
                                <option value="document">Document</option>
                                <option value="git_repository">Git Repository</option>
                            </x-form-select>
                        </div>
                    </div>

                    @if($sourceType === 'document')
                        <div>
                            <label class="block text-sm font-medium text-gray-700">File</label>
                            <input wire:model="sourceFile" type="file"
                                accept=".txt,.md,.pdf"
                                class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100" />
                            @error('sourceFile') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                            <p class="mt-1.5 text-xs text-gray-500">
                                For source code, you can also generate a repomix pack locally
                                (<code class="rounded bg-gray-100 px-1">npx repomix --style plain ./</code>)
                                and upload the output file as a document.
                            </p>
                        </div>
                    @elseif($sourceType === 'git_repository')
                        <x-form-input wire:model="sourceUrl" label="Repository URL" placeholder="https://github.com/org/repo" />
                        <x-form-input wire:model="sourceBranch" label="Branch" placeholder="main" hint="Leave blank to use main." />
                    @else
                        <x-form-input wire:model="sourceUrl" label="URL" placeholder="https://example.com/docs" />
                    @endif

                    <div class="flex gap-2">
                        <button wire:click="addSource" wire:loading.attr="disabled"
                            class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-60">
                            <span wire:loading.remove wire:target="addSource">Add & Index</span>
                            <span wire:loading wire:target="addSource">Indexing…</span>
                        </button>
                        <button wire:click="$set('showAddForm', false)"
                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        @endif

        {{-- Sources list --}}
        <div class="divide-y divide-gray-100">
            @forelse($sources as $source)
                <div>
                    <div class="flex items-center justify-between px-6 py-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-3">
                                <span class="text-sm font-medium {{ $source->is_enabled ? 'text-gray-900' : 'text-gray-400' }}">{{ $source->name }}</span>
                                @if(! $source->is_enabled)
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-400">Disabled</span>
                                @endif
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium
                                    {{ $source->status->value === 'ready' ? 'bg-green-100 text-green-700' : '' }}
                                    {{ $source->status->value === 'indexing' ? 'bg-blue-100 text-blue-700' : '' }}
                                    {{ $source->status->value === 'pending' ? 'bg-gray-100 text-gray-600' : '' }}
                                    {{ in_array($source->status->value, ['failed', 'error']) ? 'bg-red-100 text-red-700' : '' }}">
                                    {{ ucfirst($source->status->value) }}
                                </span>
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                                    {{ ucfirst($source->type->value) }}
                                </span>
                            </div>
                            <div class="mt-0.5 flex items-center gap-3 text-xs text-gray-500">
                                @if($source->source_url)
                                    <span class="truncate max-w-xs">{{ $source->source_url }}</span>
                                @elseif(isset($source->source_data['original_name']))
                                    <span>{{ $source->source_data['original_name'] }}</span>
                                @endif
                                @if($source->chunks_count > 0)
                                    <span>&middot; {{ $source->chunks_count }} chunks</span>
                                @endif
                                @if($source->indexed_at)
                                    <span>&middot; Indexed {{ $source->indexed_at->diffForHumans() }}</span>
                                @endif
                            </div>
                            @if($source->error_message)
                                <p class="mt-1 text-xs text-red-600">{{ $source->error_message }}</p>
                            @endif
                        </div>
                        <div class="ml-4 flex shrink-0 items-center gap-2">
                            {{-- Enable/disable toggle --}}
                            <button wire:click="toggleSource('{{ $source->id }}')"
                                title="{{ $source->is_enabled ? 'Disable source' : 'Enable source' }}"
                                class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full transition-colors focus:outline-none
                                    {{ $source->is_enabled ? 'bg-primary-600' : 'bg-gray-300' }}">
                                <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform
                                    {{ $source->is_enabled ? 'translate-x-4' : 'translate-x-0.5' }}"></span>
                            </button>
                            @if($source->chunks_count > 0)
                                <button wire:click="viewChunks('{{ $source->id }}')"
                                    class="rounded border border-gray-300 px-2 py-1 text-xs {{ $viewingSourceId === $source->id ? 'bg-primary-50 border-primary-300 text-primary-700' : 'text-gray-600 hover:bg-gray-50' }}">
                                    {{ $viewingSourceId === $source->id ? 'Hide Chunks' : 'View Chunks' }}
                                </button>
                            @endif
                            @if(in_array($source->status->value, ['ready', 'failed', 'error']))
                                <button wire:click="reindex('{{ $source->id }}')"
                                    class="rounded border border-gray-300 px-2 py-1 text-xs text-gray-600 hover:bg-gray-50">
                                    Re-index
                                </button>
                            @endif
                            <button wire:click="deleteSource('{{ $source->id }}')"
                                wire:confirm="Delete this knowledge source?"
                                class="rounded border border-red-200 px-2 py-1 text-xs text-red-600 hover:bg-red-50">
                                Delete
                            </button>
                        </div>
                    </div>

                    {{-- Inline chunk browser --}}
                    @if($viewingSourceId === $source->id && $viewingChunks !== null)
                        <div class="border-t border-gray-100 bg-gray-50 px-6 py-4">
                            <div class="mb-3 flex items-center gap-3">
                                <p class="text-xs font-semibold text-gray-700">Chunks ({{ $viewingChunks->total() }} total)</p>
                                <div class="flex-1 max-w-xs">
                                    <input wire:model.live.debounce.400ms="chunkSearch" type="text"
                                        placeholder="Search chunks…"
                                        class="w-full rounded border border-gray-300 px-2 py-1 text-xs focus:border-primary-400 focus:ring-primary-400" />
                                </div>
                            </div>

                            <div class="space-y-2">
                                @forelse($viewingChunks as $chunk)
                                    <div class="rounded-lg border border-gray-200 bg-white p-3" x-data="{ expanded: false }">
                                        <div class="mb-1 flex items-center justify-between gap-2">
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs font-mono text-gray-500">#{{ $chunk->chunk_index }}</span>
                                                @if($chunk->access_level !== 'public')
                                                    <span class="rounded-full bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-700">{{ $chunk->access_level }}</span>
                                                @endif
                                                @if(isset($chunk->metadata['file']))
                                                    <span class="font-mono text-xs text-gray-400 truncate max-w-xs">{{ $chunk->metadata['file'] }}</span>
                                                @endif
                                            </div>
                                            <button @click="expanded = !expanded" class="shrink-0 text-xs text-primary-600 hover:text-primary-800"
                                                x-text="expanded ? 'Collapse' : 'Expand'"></button>
                                        </div>
                                        <p class="text-xs text-gray-700 leading-relaxed"
                                            x-show="!expanded">{{ mb_substr($chunk->content, 0, 300) }}{{ strlen($chunk->content) > 300 ? '…' : '' }}</p>
                                        <p class="text-xs text-gray-700 leading-relaxed whitespace-pre-wrap font-mono"
                                            x-show="expanded" x-cloak>{{ $chunk->content }}</p>
                                    </div>
                                @empty
                                    <p class="text-sm text-gray-400">No chunks match your search.</p>
                                @endforelse
                            </div>

                            {{-- Pagination --}}
                            @if($viewingChunks->hasPages())
                                <div class="mt-3 flex items-center justify-between text-xs text-gray-500">
                                    <span>Page {{ $viewingChunks->currentPage() }} of {{ $viewingChunks->lastPage() }}</span>
                                    <div class="flex gap-2">
                                        @if($viewingChunks->onFirstPage())
                                            <span class="cursor-not-allowed opacity-40">← Prev</span>
                                        @else
                                            <button wire:click="$set('chunkPage', {{ $chunkPage - 1 }})" class="text-primary-600 hover:text-primary-800">← Prev</button>
                                        @endif
                                        @if($viewingChunks->hasMorePages())
                                            <button wire:click="$set('chunkPage', {{ $chunkPage + 1 }})" class="text-primary-600 hover:text-primary-800">Next →</button>
                                        @else
                                            <span class="cursor-not-allowed opacity-40">Next →</span>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @empty
                <div class="px-6 py-10 text-center text-sm text-gray-400">
                    No knowledge sources yet. Add documents or URLs to enhance your chatbot's answers.
                </div>
            @endforelse
        </div>
    </div>

    {{-- RAG Test Panel --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="border-b border-gray-200 px-6 py-4">
            <h3 class="text-sm font-semibold text-gray-900">Retrieval Test</h3>
            <p class="mt-0.5 text-xs text-gray-500">Test how well your knowledge base answers a query.</p>
        </div>
        <div class="px-6 py-4 space-y-4">
            <div class="flex gap-3">
                <div class="flex-1">
                    <x-form-input wire:model="testQuery" placeholder="Enter a test question…" />
                </div>
                <button wire:click="runRagTest" wire:loading.attr="disabled"
                    class="shrink-0 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-60">
                    <span wire:loading.remove wire:target="runRagTest">Test</span>
                    <span wire:loading wire:target="runRagTest">Searching…</span>
                </button>
            </div>

            @if(!empty($testResults))
                <div class="space-y-3">
                    <p class="text-xs font-medium text-gray-500">Top {{ count($testResults) }} results:</p>
                    @foreach($testResults as $result)
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                            <div class="mb-1 flex items-center justify-between gap-2">
                                <span class="text-xs font-medium text-gray-600">{{ $result['source_name'] }} · chunk #{{ $result['chunk_index'] }}</span>
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium
                                    {{ $result['similarity'] >= 0.8 ? 'bg-green-100 text-green-700' : ($result['similarity'] >= 0.65 ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600') }}">
                                    {{ number_format($result['similarity'] * 100, 1) }}% match
                                </span>
                            </div>
                            <p class="text-xs text-gray-700">{{ $result['content'] }}</p>
                        </div>
                    @endforeach
                </div>
            @elseif($testQuery && !$testRunning)
                <p class="text-sm text-gray-400">No results above 50% similarity. Try a different query or add more knowledge sources.</p>
            @endif
        </div>
    </div>
</div>
