<div class="space-y-6">
    {{-- Flash message --}}
    @if(session()->has('message'))
        <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <p class="text-sm text-gray-500">Manage structured knowledge bases for your agents. Documents are chunked and stored as searchable vector embeddings.</p>
        </div>
        <button wire:click="openCreate"
            class="flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
            <i class="fas fa-plus"></i>
            New Knowledge Base
        </button>
    </div>

    {{-- Create Modal --}}
    @if($showCreateModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        x-data x-on:keydown.escape.window="$wire.set('showCreateModal', false)">
        <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
            <h3 class="mb-4 text-lg font-semibold text-gray-900">New Knowledge Base</h3>

            <div class="space-y-4">
                <x-form-input wire:model="createName" label="Name" placeholder="e.g. Product Docs Q2"
                    :error="$errors->first('createName')" />

                <x-form-textarea wire:model="createDescription" label="Description (optional)" rows="2"
                    placeholder="What kind of documents will this knowledge base contain?" />

                <x-form-select wire:model="createAgentId" label="Assign to Agent (optional)">
                    <option value="">No agent — team-wide</option>
                    @foreach($agents as $agent)
                        <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                    @endforeach
                </x-form-select>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <button type="button" wire:click="$set('showCreateModal', false)"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="button" wire:click="create"
                    class="rounded-lg bg-primary-600 px-5 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Create
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Ingest Modal --}}
    @if($ingestKbId)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        x-data x-on:keydown.escape.window="$wire.set('ingestKbId', null)">
        <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
            <h3 class="mb-4 text-lg font-semibold text-gray-900">Add Document</h3>

            <div class="space-y-4">
                <x-form-input wire:model="ingestSourceName" label="Source Name (optional)"
                    placeholder="e.g. README.md, API guide" />

                <x-form-textarea wire:model="ingestContent" label="Content" rows="8"
                    placeholder="Paste your document content here…"
                    :mono="true"
                    :error="$errors->first('ingestContent')" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <button type="button" wire:click="$set('ingestKbId', null)"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="button" wire:click="ingest"
                    class="rounded-lg bg-primary-600 px-5 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Ingest
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Knowledge Base List --}}
    @if($knowledgeBases->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-12 text-center">
            <i class="fas fa-book-open-reader mb-3 text-3xl text-gray-300"></i>
            <p class="text-sm font-medium text-gray-500">No knowledge bases yet</p>
            <p class="mt-1 text-xs text-gray-400">Create your first knowledge base and start ingesting documents.</p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($knowledgeBases as $kb)
                @php
                    $statusColor = match($kb->status->color()) {
                        'green' => 'bg-green-100 text-green-700',
                        'blue' => 'bg-blue-100 text-blue-700',
                        'red' => 'bg-red-100 text-red-700',
                        default => 'bg-gray-100 text-gray-600',
                    };
                @endphp
                <div class="flex flex-col rounded-xl border border-gray-200 bg-white p-5">
                    <div class="mb-3 flex items-start justify-between">
                        <div class="min-w-0 flex-1">
                            <h3 class="truncate text-sm font-semibold text-gray-900">{{ $kb->name }}</h3>
                            @if($kb->description)
                                <p class="mt-0.5 text-xs text-gray-500">{{ Str::limit($kb->description, 80) }}</p>
                            @endif
                        </div>
                        <span class="ml-2 shrink-0 rounded-full px-2 py-0.5 text-xs font-medium {{ $statusColor }}">
                            {{ $kb->status->label() }}
                        </span>
                    </div>

                    <dl class="mb-4 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <dt class="text-gray-400">Chunks</dt>
                            <dd class="font-medium text-gray-700">{{ number_format($kb->chunks_count) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-400">Agent</dt>
                            <dd class="truncate font-medium text-gray-700">{{ $kb->agent?->name ?? '—' }}</dd>
                        </div>
                        @if($kb->last_ingested_at)
                        <div class="col-span-2">
                            <dt class="text-gray-400">Last ingested</dt>
                            <dd class="font-medium text-gray-700">{{ $kb->last_ingested_at->diffForHumans() }}</dd>
                        </div>
                        @endif
                        @if($kb->ragflow_enabled)
                        <div class="col-span-2">
                            <dt class="text-gray-400">RAGFlow</dt>
                            <dd class="font-medium text-green-700">{{ $kb->ragflow_dataset_id ? 'Synced' : 'Enabled' }}</dd>
                        </div>
                        @endif
                    </dl>

                    <div class="mt-auto flex items-center gap-2">
                        <button wire:click="openIngest('{{ $kb->id }}')"
                            class="flex-1 rounded-lg border border-primary-300 bg-primary-50 px-3 py-1.5 text-xs font-medium text-primary-700 hover:bg-primary-100">
                            + Add Document
                        </button>
                        <button wire:click="delete('{{ $kb->id }}')"
                            wire:confirm="Delete this knowledge base and all its chunks?"
                            class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- File Upload Section --}}
    <div class="rounded-xl border border-gray-200 bg-white p-6">
        <h3 class="mb-1 text-sm font-semibold text-gray-900">Upload to Memory Store</h3>
        <p class="mb-4 text-xs text-gray-500">Upload PDF, TXT, MD, or CSV files to create memory chunks directly accessible to agents.</p>
        <livewire:memory.knowledge-upload-panel />
    </div>
</div>
