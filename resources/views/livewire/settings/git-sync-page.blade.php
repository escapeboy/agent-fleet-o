<div class="mx-auto max-w-3xl space-y-6 py-2">
    @if (session('message'))
        <div class="rounded-lg border border-primary-200 bg-primary-50 px-4 py-2 text-sm text-primary-700">
            {{ session('message') }}
        </div>
    @endif

    <div>
        <h1 class="text-lg font-semibold text-gray-900">Git Sync</h1>
        <p class="mt-0.5 text-sm text-gray-500">
            Mirror your team's context and workflows into a Git repository — your files, your repo, with full history.
        </p>
    </div>

    {{-- Context filesystem sync --}}
    <div class="rounded-xl border border-gray-200 bg-white p-5">
        <div class="mb-4">
            <h2 class="text-sm font-semibold text-gray-900">Context filesystem</h2>
            <p class="mt-0.5 text-xs text-gray-500">
                One-way export of artifacts and memory as versioned markdown files.
            </p>
        </div>

        @if($repos->isEmpty())
            <p class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-500">
                No Git repositories connected yet. Add one under
                <a href="{{ route('git-repositories.index') }}" class="text-primary-600 hover:underline">Git Repositories</a> first.
            </p>
        @else
            <div class="space-y-4">
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600">Repository</label>
                    <select wire:model="selectedRepoId"
                        class="w-full rounded-lg border border-gray-300 py-2.5 px-3 text-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">— Select a repository —</option>
                        @foreach($repos as $repo)
                            <option value="{{ $repo->id }}">{{ $repo->name }}</option>
                        @endforeach
                    </select>
                    @error('selectedRepoId') <span class="mt-1 text-xs text-red-600">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600">Branch</label>
                    <input type="text" wire:model="branch"
                        class="w-full rounded-lg border border-gray-300 py-2.5 px-3 text-sm focus:border-primary-500 focus:ring-primary-500">
                    @error('branch') <span class="mt-1 text-xs text-red-600">{{ $message }}</span> @enderror
                </div>

                <div class="flex gap-6">
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="syncArtifacts"
                            class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                        Sync artifacts
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="syncMemory"
                            class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                        Sync memory
                    </label>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button wire:click="saveContextSync" wire:loading.attr="disabled"
                        class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                        Save
                    </button>
                    @if($contextSync)
                        <button wire:click="exportNow" wire:loading.attr="disabled"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <i class="fa-solid fa-cloud-arrow-up mr-1"></i> Export now
                        </button>
                        <button wire:click="removeContextSync"
                            wire:confirm="Remove the context Git sync?"
                            class="rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                            Remove
                        </button>
                    @endif
                </div>

                @if($contextSync)
                    <p class="text-xs text-gray-400">
                        @if($contextSync->last_pushed_at)
                            Last pushed {{ $contextSync->last_pushed_at->diffForHumans() }}
                            @if($contextSync->last_pushed_sha)
                                · <code>{{ \Illuminate\Support\Str::limit($contextSync->last_pushed_sha, 10, '') }}</code>
                            @endif
                        @else
                            Configured — not pushed yet.
                        @endif
                    </p>
                @endif
            </div>
        @endif
    </div>

    {{-- Workflow YAML syncs --}}
    <div class="rounded-xl border border-gray-200 bg-white p-5">
        <div class="mb-4">
            <h2 class="text-sm font-semibold text-gray-900">Workflow syncs</h2>
            <p class="mt-0.5 text-xs text-gray-500">
                Each linked workflow is exported as YAML to its repository on every save.
            </p>
        </div>

        @forelse($workflowSyncs as $sync)
            <div class="mb-2 flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2">
                <div class="text-sm text-gray-700">
                    <span class="font-medium">{{ $sync->workflow?->name ?? 'Unknown workflow' }}</span>
                    <span class="text-gray-400">→ {{ $sync->gitRepository?->name ?? 'Unknown repo' }}</span>
                    <span class="text-xs text-gray-400">({{ $sync->branch }})</span>
                </div>
                <button wire:click="removeWorkflowSync('{{ $sync->id }}')"
                    wire:confirm="Unlink this workflow sync?"
                    class="text-xs font-medium text-red-600 hover:underline">Remove</button>
            </div>
        @empty
            <p class="mb-3 text-sm text-gray-400">No workflow syncs linked yet.</p>
        @endforelse

        @if($workflows->isNotEmpty() && $repos->isNotEmpty())
            <div class="mt-3 flex flex-col gap-2 border-t border-gray-100 pt-3 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <label class="mb-1 block text-xs font-medium text-gray-600">Workflow</label>
                    <select wire:model="workflowSyncWorkflowId"
                        class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">— Select —</option>
                        @foreach($workflows as $workflow)
                            <option value="{{ $workflow->id }}">{{ $workflow->name }}</option>
                        @endforeach
                    </select>
                    @error('workflowSyncWorkflowId') <span class="mt-1 text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
                <div class="flex-1">
                    <label class="mb-1 block text-xs font-medium text-gray-600">Repository</label>
                    <select wire:model="workflowSyncRepoId"
                        class="w-full rounded-lg border border-gray-300 py-2 px-3 text-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">— Select —</option>
                        @foreach($repos as $repo)
                            <option value="{{ $repo->id }}">{{ $repo->name }}</option>
                        @endforeach
                    </select>
                    @error('workflowSyncRepoId') <span class="mt-1 text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
                <button wire:click="createWorkflowSync" wire:loading.attr="disabled"
                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                    Link
                </button>
            </div>
        @endif
    </div>
</div>
