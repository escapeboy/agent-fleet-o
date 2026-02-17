<div class="space-y-4">
    <div class="flex items-center justify-between">
        <div>
            <h3 class="text-base font-semibold text-gray-900">AI Project Expansion</h3>
            <p class="text-sm text-gray-500">Decompose your project goal into individual experiments using AI.</p>
        </div>
    </div>

    @if($error)
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700">
            {{ $error }}
        </div>
    @endif

    {{-- Goal input --}}
    @if(empty($features))
        <div class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700">Project Goal</label>
                <textarea wire:model="goal" rows="3" class="mt-1 w-full rounded-lg border-gray-300 text-sm"
                    placeholder="Describe what you want this project to accomplish..."></textarea>
                @error('goal') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Additional Context <span class="text-gray-400">(optional)</span></label>
                <textarea wire:model="context" rows="2" class="mt-1 w-full rounded-lg border-gray-300 text-sm"
                    placeholder="Tech stack, constraints, existing codebase details..."></textarea>
            </div>

            <button wire:click="expand" wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="expand">Expand with AI</span>
                <span wire:loading wire:target="expand" class="flex items-center gap-2">
                    <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    Analyzing...
                </span>
            </button>
        </div>
    @else
        {{-- Feature list --}}
        <div class="rounded-lg border border-gray-200 bg-white">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                <div class="flex items-center gap-3">
                    <span class="text-sm font-medium text-gray-900">{{ count($features) }} Features</span>
                    @if($costEstimate)
                        <span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">
                            ~{{ number_format($costEstimate) }} credits estimated
                        </span>
                    @endif
                </div>
                <button wire:click="$set('features', [])" class="text-sm text-gray-500 hover:text-gray-700">
                    Start Over
                </button>
            </div>

            <div class="divide-y divide-gray-100">
                @foreach($features as $index => $feature)
                    <div class="flex items-start gap-3 px-4 py-3">
                        <span class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-medium text-gray-600">
                            {{ $index + 1 }}
                        </span>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <h4 class="text-sm font-medium text-gray-900">{{ $feature['title'] ?? 'Feature '.($index + 1) }}</h4>
                                @php
                                    $priorityColors = [
                                        'high' => 'bg-red-100 text-red-700',
                                        'medium' => 'bg-yellow-100 text-yellow-700',
                                        'low' => 'bg-green-100 text-green-700',
                                    ];
                                @endphp
                                <span class="rounded-full px-1.5 py-0.5 text-xs font-medium {{ $priorityColors[$feature['priority'] ?? 'medium'] ?? 'bg-gray-100 text-gray-700' }}">
                                    {{ $feature['priority'] ?? 'medium' }}
                                </span>
                            </div>
                            <p class="mt-0.5 text-xs text-gray-500">{{ $feature['description'] ?? '' }}</p>
                            <div class="mt-1 flex items-center gap-3 text-xs text-gray-400">
                                @if(!empty($feature['dependencies']))
                                    <span>Depends on: {{ implode(', ', array_map(fn($d) => '#'.($d + 1), $feature['dependencies'])) }}</span>
                                @endif
                                @if($feature['estimated_credits'] ?? null)
                                    <span>~{{ $feature['estimated_credits'] }} credits</span>
                                @endif
                                @if($feature['suggested_agent_role'] ?? null)
                                    <span>Agent: {{ $feature['suggested_agent_role'] }}</span>
                                @endif
                            </div>
                        </div>
                        <button wire:click="removeFeature({{ $index }})" class="text-gray-400 hover:text-red-500" title="Remove">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Materialize button --}}
        <div class="flex items-center justify-end gap-3">
            <button wire:click="$set('features', [])" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Cancel
            </button>
            <button wire:click="materialize" wire:loading.attr="disabled" wire:confirm="Create {{ count($features) }} experiments? Estimated cost: {{ number_format($costEstimate ?? 0) }} credits."
                class="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="materialize">Create {{ count($features) }} Experiments</span>
                <span wire:loading wire:target="materialize">Creating...</span>
            </button>
        </div>
    @endif
</div>
