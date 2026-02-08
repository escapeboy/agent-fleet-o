<div>
    {{-- Header Actions --}}
    <div class="mb-6 flex items-center gap-3">
        <a href="{{ route('workflows.index') }}" class="text-gray-500 hover:text-gray-700">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        </a>

        <div class="flex-1">
            <div class="flex items-center gap-3">
                <h2 class="text-lg font-semibold text-gray-900">{{ $workflow->name }}</h2>
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-{{ $workflow->status->color() }}-100 text-{{ $workflow->status->color() }}-800">
                    {{ $workflow->status->label() }}
                </span>
                <span class="text-xs text-gray-400">v{{ $workflow->version }}</span>
            </div>
            @if($workflow->description)
                <p class="mt-1 text-sm text-gray-500">{{ $workflow->description }}</p>
            @endif
        </div>

        <div class="flex items-center gap-2">
            <button wire:click="recalculateCost" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Recalculate Cost
            </button>
            <button wire:click="duplicate" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Duplicate
            </button>
            <a href="{{ route('workflows.edit', $workflow) }}" class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                Edit
            </a>
            <button wire:click="archive" wire:confirm="Are you sure you want to archive this workflow?"
                    class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">
                Archive
            </button>
        </div>
    </div>

    {{-- Flash Messages --}}
    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('success') }}</div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Left: Workflow info --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- Stats cards --}}
            <div class="grid grid-cols-4 gap-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-2xl font-bold text-gray-900">{{ $workflow->nodeCount() }}</div>
                    <div class="text-xs text-gray-500">Total Nodes</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-2xl font-bold text-gray-900">{{ $agentNodes->count() }}</div>
                    <div class="text-xs text-gray-500">Agent Nodes</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-2xl font-bold text-gray-900">{{ $workflow->edges->count() }}</div>
                    <div class="text-xs text-gray-500">Connections</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-2xl font-bold text-gray-900">
                        {{ $workflow->estimated_cost_credits ? number_format($workflow->estimated_cost_credits) : '-' }}
                    </div>
                    <div class="text-xs text-gray-500">Est. Credits</div>
                </div>
            </div>

            {{-- Node list --}}
            <div class="rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-200 px-4 py-3">
                    <h3 class="text-sm font-semibold text-gray-900">Workflow Nodes</h3>
                </div>
                <div class="divide-y divide-gray-100">
                    @foreach($workflow->nodes->sortBy('order') as $node)
                        <div class="flex items-center gap-3 px-4 py-3">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg
                                {{ match($node->type->value) {
                                    'start' => 'bg-green-100 text-green-600',
                                    'end' => 'bg-red-100 text-red-600',
                                    'agent' => 'bg-purple-100 text-purple-600',
                                    'conditional' => 'bg-yellow-100 text-yellow-600',
                                    default => 'bg-gray-100 text-gray-600',
                                } }}">
                                <span class="text-xs font-medium">{{ strtoupper(substr($node->type->value, 0, 1)) }}</span>
                            </span>
                            <div class="flex-1">
                                <div class="text-sm font-medium text-gray-700">{{ $node->label }}</div>
                                @if($node->agent)
                                    <div class="text-xs text-gray-400">Agent: {{ $node->agent->name }}</div>
                                @endif
                                @if($node->skill)
                                    <div class="text-xs text-gray-400">Skill: {{ $node->skill->name }}</div>
                                @endif
                            </div>
                            <span class="text-xs text-gray-400">{{ $node->type->label() }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Recent experiments --}}
            <div class="rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-200 px-4 py-3">
                    <h3 class="text-sm font-semibold text-gray-900">Recent Experiments</h3>
                </div>
                @if($experiments->isEmpty())
                    <div class="px-4 py-8 text-center text-sm text-gray-400">
                        No experiments have used this workflow yet.
                    </div>
                @else
                    <div class="divide-y divide-gray-100">
                        @foreach($experiments as $exp)
                            <div class="flex items-center gap-3 px-4 py-3">
                                <div class="flex-1">
                                    <a href="{{ route('experiments.show', $exp) }}" class="text-sm font-medium text-primary-600 hover:text-primary-800">
                                        {{ $exp->title }}
                                    </a>
                                </div>
                                <x-status-badge :status="$exp->status->value" />
                                <span class="text-xs text-gray-400">{{ $exp->created_at->diffForHumans() }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Right: Metadata --}}
        <div class="space-y-6">
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Details</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Created by</dt>
                        <dd class="font-medium text-gray-700">{{ $workflow->user?->name ?? 'System' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Version</dt>
                        <dd class="font-medium text-gray-700">{{ $workflow->version }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Max Loop Iterations</dt>
                        <dd class="font-medium text-gray-700">{{ $workflow->max_loop_iterations }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Created</dt>
                        <dd class="font-medium text-gray-700">{{ $workflow->created_at->format('M j, Y H:i') }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Updated</dt>
                        <dd class="font-medium text-gray-700">{{ $workflow->updated_at->diffForHumans() }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</div>
