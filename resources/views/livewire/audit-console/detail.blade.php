    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-6 flex items-center gap-4">
            <a href="{{ route('audit-console.index') }}" class="text-gray-400 hover:text-white text-sm">
                &larr; Back
            </a>
            <h1 class="text-xl font-semibold text-white">
                {{ $this->decision->workflow_name }}
                <span class="ml-2 text-sm text-gray-500">{{ $this->decision->workflow_version }}</span>
            </h1>
            <span class="ml-auto rounded-full px-3 py-1 text-xs font-medium
                {{ $this->decision->status->value === 'completed' ? 'bg-green-900/30 text-green-400' :
                   ($this->decision->status->value === 'tampered' ? 'bg-orange-900/30 text-orange-400' : 'bg-gray-800 text-gray-400') }}">
                {{ $this->decision->status->value }}
            </span>
        </div>

        @if(session('success'))
            <div class="mb-4 rounded-md bg-green-900/40 border border-green-700 px-4 py-3 text-sm text-green-300">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="mb-4 rounded-md bg-red-900/40 border border-red-700 px-4 py-3 text-sm text-red-300">
                {{ session('error') }}
            </div>
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

            {{-- Actions --}}
            <div class="lg:col-span-2 flex gap-3">
                <button wire:click="verifyBundle"
                        class="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 transition-colors">
                    Verify Bundle
                </button>
                @if($isAdmin)
                    <button wire:click="replayBundle"
                            class="rounded-md border border-gray-700 px-4 py-2 text-sm font-medium text-gray-300 hover:text-white transition-colors">
                        Replay
                    </button>
                    @if($this->decision->bundle_path)
                        <button wire:click="downloadBundle"
                                class="rounded-md border border-gray-700 px-4 py-2 text-sm font-medium text-gray-300 hover:text-white transition-colors">
                            Download Bundle
                        </button>
                    @endif
                @endif
            </div>

            {{-- DAG Visualization (Cytoscape placeholder) --}}
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-4">
                <h2 class="mb-3 text-sm font-semibold text-gray-300">Workflow Graph</h2>
                <div wire:ignore
                     id="boruna-dag-container"
                     class="h-48 w-full rounded-md bg-gray-800"
                     data-elements="{{ json_encode($cytoElements) }}">
                </div>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        var container = document.getElementById('boruna-dag-container');
                        if (typeof cytoscape !== 'undefined' && container) {
                            var elements = JSON.parse(container.dataset.elements || '{}');
                            var cy = cytoscape({
                                container: container,
                                elements: [...(elements.nodes || []), ...(elements.edges || [])],
                                style: [
                                    { selector: 'node', style: { label: 'data(label)', 'background-color': '#3b82f6', color: '#fff', 'font-size': '11px' } },
                                    { selector: 'edge', style: { 'line-color': '#6b7280', 'target-arrow-color': '#6b7280', 'target-arrow-shape': 'triangle', 'curve-style': 'bezier' } }
                                ],
                                layout: { name: 'breadthfirst', directed: true }
                            });
                        }
                    });
                </script>
            </div>

            {{-- Run Metadata --}}
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-4">
                <h2 class="mb-3 text-sm font-semibold text-gray-300">Run Details</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Run ID</dt>
                        <dd class="font-mono text-gray-300 text-xs">{{ $this->decision->run_id }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Shadow Mode</dt>
                        <dd class="text-gray-300">{{ $this->decision->shadow_mode ? 'Yes' : 'No' }}</dd>
                    </div>
                    @if($this->decision->shadow_discrepancy !== null)
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Discrepancy</dt>
                            <dd class="text-gray-300">{{ number_format($this->decision->shadow_discrepancy * 100, 1) }}%</dd>
                        </div>
                    @endif
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Created</dt>
                        <dd class="text-gray-300">{{ $this->decision->created_at->toDateTimeString() }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Hash Chain --}}
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-4 lg:col-span-2">
                <h2 class="mb-3 text-sm font-semibold text-gray-300">Hash Chain</h2>
                @if(empty($evidenceChain))
                    <p class="text-sm text-gray-500">No hash chain recorded.</p>
                @else
                    <div class="space-y-2">
                        @foreach($evidenceChain as $entry)
                            <details class="group rounded-md border border-gray-700 bg-gray-800/50">
                                <summary class="flex cursor-pointer items-center gap-2 px-3 py-2 text-xs text-gray-400 hover:text-gray-200">
                                    <span class="font-mono">{{ $entry['hash'] ?? 'no-hash' }}</span>
                                    <span class="ml-auto">{{ $entry['event'] ?? 'event' }}</span>
                                </summary>
                                <pre class="overflow-x-auto px-3 py-2 text-xs text-gray-400">{{ json_encode($entry, JSON_PRETTY_PRINT) }}</pre>
                            </details>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- LLM Calls --}}
            @if(!empty($llmCalls))
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-4 lg:col-span-2">
                    <h2 class="mb-3 text-sm font-semibold text-gray-300">LLM Calls</h2>
                    <div class="space-y-3">
                        @foreach($llmCalls as $call)
                            <div class="rounded-md border border-gray-700 bg-gray-800/50 p-3">
                                <p class="mb-1 text-xs font-semibold text-gray-400">Prompt</p>
                                <pre class="whitespace-pre-wrap text-xs text-gray-300">{{ $call['prompt'] ?? '—' }}</pre>
                                <p class="mt-2 mb-1 text-xs font-semibold text-gray-400">Response</p>
                                <pre class="whitespace-pre-wrap text-xs text-gray-300">{{ $call['response'] ?? '—' }}</pre>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

        </div>
    </div>
